<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function input_json(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);

    if (is_array($data)) {
        return $data;
    }

    if (is_array($_POST) && !empty($_POST)) {
        return $_POST;
    }

    return [];
}

function env_value(string $key, string $default = ''): string
{
    $value = getenv($key);

    if ($value === false) {
        return $default;
    }

    return trim((string) $value);
}

function db_config(): array
{
    return [
        'server' => env_value(
            'DB_SERVER',
            env_value('AZURE_SQL_SERVERNAME', 'tcp:liderdriver.database.windows.net,1433')
        ),
        'database' => env_value('DB_DATABASE', env_value('AZURE_SQL_DATABASE', 'clerioapp')),
        'username' => env_value(
            'DB_USERNAME',
            env_value('LIDERDRIVER_DB_USER', env_value('AZURE_SQL_UID', ''))
        ),
        'password' => env_value(
            'DB_PASSWORD',
            env_value('LIDERDRIVER_DB_PASS', env_value('AZURE_SQL_PWD', ''))
        ),
        'encrypt' => env_value('DB_ENCRYPT', '1') !== '0',
        'trust_certificate' => env_value('DB_TRUST_CERTIFICATE', '0') === '1',
        'timeout' => (int) env_value('DB_LOGIN_TIMEOUT', '30'),
    ];
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = db_config();

    if ($config['database'] === '' || $config['username'] === '' || $config['password'] === '') {
        json_response([
            'ok' => false,
            'error' => 'Configuracao do banco incompleta. Defina DB_DATABASE, DB_USERNAME e DB_PASSWORD.',
        ], 500);
    }

    $dsn = sprintf(
        'sqlsrv:server=%s;Database=%s;LoginTimeout=%d;Encrypt=%d;TrustServerCertificate=%d',
        $config['server'],
        $config['database'],
        $config['timeout'],
        $config['encrypt'] ? 1 : 0,
        $config['trust_certificate'] ? 1 : 0
    );

    $pdo = new PDO($dsn, $config['username'], $config['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function database_health(): array
{
    $config = db_config();

    try {
        $connection = db();
        $statement = $connection->query('SELECT 1 AS ok');
        $statement->fetch();

        return [
            'status' => 'online',
            'server' => $config['server'],
            'name' => $config['database'],
        ];
    } catch (Throwable $exception) {
        return [
            'status' => 'offline',
            'server' => $config['server'],
            'name' => $config['database'],
            'error' => $exception->getMessage(),
        ];
    }
}

function current_environment(): string
{
    return env_value('APP_ENV', 'production');
}

function quote_identifier(string $value): string
{
    return '[' . str_replace(']', ']]', $value) . ']';
}

function table_exists(string $tableName): bool
{
    $statement = db()->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_TYPE = :table_type
           AND TABLE_NAME = :table_name'
    );
    $statement->execute([
        'table_type' => 'BASE TABLE',
        'table_name' => $tableName,
    ]);

    return (bool) $statement->fetchColumn();
}

function find_table_by_suffix(string $suffix): ?string
{
    $statement = db()->prepare(
        'SELECT TOP 1 TABLE_NAME
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_TYPE = :table_type
           AND TABLE_NAME LIKE :table_name
         ORDER BY TABLE_NAME ASC'
    );
    $statement->execute([
        'table_type' => 'BASE TABLE',
        'table_name' => 'tb%_' . $suffix,
    ]);

    $tableName = $statement->fetchColumn();
    return is_string($tableName) && $tableName !== '' ? $tableName : null;
}

function next_tb_sequence(): int
{
    $statement = db()->query(
        "SELECT MAX(TRY_CONVERT(int, SUBSTRING(TABLE_NAME, 3, CHARINDEX('_', TABLE_NAME + '_') - 3))) AS max_seq
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_TYPE = 'BASE TABLE'
           AND TABLE_NAME LIKE 'tb%\\_%'"
    );

    $max = (int) ($statement->fetchColumn() ?: 0);
    return $max + 1;
}

function services_table_name(): string
{
    static $tableName = null;

    if (is_string($tableName) && $tableName !== '') {
        return $tableName;
    }

    $existing = find_table_by_suffix('planos_transporte_executivo');

    if ($existing !== null) {
        $tableName = $existing;
        return $tableName;
    }

    $nextSequence = next_tb_sequence();
    $candidate = 'tb' . $nextSequence . '_planos_transporte_executivo';
    $quotedTable = quote_identifier($candidate);
    $quotedSlugIndex = quote_identifier('ux_' . $candidate . '_slug');
    $quotedOrderIndex = quote_identifier('ix_' . $candidate . '_ordem_ativo');
    $quotedCityIndex = quote_identifier('ix_' . $candidate . '_cidade_ativo');

    db()->exec(
        "CREATE TABLE {$quotedTable} (
            id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
            slug NVARCHAR(60) NOT NULL,
            nome NVARCHAR(80) NOT NULL,
            cidade NVARCHAR(120) NOT NULL,
            descricao_curta NVARCHAR(220) NOT NULL,
            descricao_completa NVARCHAR(MAX) NOT NULL,
            valor_diaria DECIMAL(10,2) NOT NULL CONSTRAINT " . quote_identifier('df_' . $candidate . '_valor_diaria') . " DEFAULT (0),
            horas_disponiveis INT NOT NULL CONSTRAINT " . quote_identifier('df_' . $candidate . '_horas_disponiveis') . " DEFAULT (0),
            limite_km INT NOT NULL CONSTRAINT " . quote_identifier('df_' . $candidate . '_limite_km') . " DEFAULT (0),
            valor_hora_extra DECIMAL(10,2) NOT NULL CONSTRAINT " . quote_identifier('df_' . $candidate . '_valor_hora_extra') . " DEFAULT (0),
            valor_km_adicional DECIMAL(10,2) NOT NULL CONSTRAINT " . quote_identifier('df_' . $candidate . '_valor_km_adicional') . " DEFAULT (0),
            ordem_exibicao INT NOT NULL CONSTRAINT " . quote_identifier('df_' . $candidate . '_ordem') . " DEFAULT (0),
            ativo BIT NOT NULL CONSTRAINT " . quote_identifier('df_' . $candidate . '_ativo') . " DEFAULT (1),
            created_at DATETIME2(0) NOT NULL CONSTRAINT " . quote_identifier('df_' . $candidate . '_created_at') . " DEFAULT (SYSUTCDATETIME()),
            updated_at DATETIME2(0) NOT NULL CONSTRAINT " . quote_identifier('df_' . $candidate . '_updated_at') . " DEFAULT (SYSUTCDATETIME())
        )"
    );

    db()->exec("CREATE UNIQUE INDEX {$quotedSlugIndex} ON {$quotedTable} (slug)");
    db()->exec("CREATE INDEX {$quotedOrderIndex} ON {$quotedTable} (ativo, ordem_exibicao)");
    db()->exec("CREATE INDEX {$quotedCityIndex} ON {$quotedTable} (cidade, ativo, ordem_exibicao)");

    $tableName = $candidate;
    seed_services_table($tableName);

    return $tableName;
}

function seed_services_table(string $tableName): void
{
    $countStatement = db()->query('SELECT COUNT(*) FROM ' . quote_identifier($tableName));
    $count = (int) $countStatement->fetchColumn();

    if ($count > 0) {
        return;
    }

    $items = [
        [
            'slug' => 'gold',
            'nome' => 'Plano Gold',
            'cidade' => 'Brasilia',
            'descricao_curta' => 'Diaria executiva com motorista a disposicao para agendas de alto nivel em Brasilia.',
            'descricao_completa' => 'Plano Gold com 10 horas a disposicao, limite de 120 km, hora extra de R$ 60 e adicional de R$ 4 por km excedente.',
            'valor_diaria' => 650.00,
            'horas_disponiveis' => 10,
            'limite_km' => 120,
            'valor_hora_extra' => 60.00,
            'valor_km_adicional' => 4.00,
            'ordem_exibicao' => 1,
            'ativo' => 1,
        ],
    ];

    $statement = db()->prepare(
        'INSERT INTO ' . quote_identifier($tableName) . ' (
            slug, nome, cidade, descricao_curta, descricao_completa, valor_diaria, horas_disponiveis, limite_km, valor_hora_extra, valor_km_adicional, ordem_exibicao, ativo, created_at, updated_at
        ) VALUES (
            :slug, :nome, :cidade, :descricao_curta, :descricao_completa, :valor_diaria, :horas_disponiveis, :limite_km, :valor_hora_extra, :valor_km_adicional, :ordem_exibicao, :ativo, SYSUTCDATETIME(), SYSUTCDATETIME()
        )'
    );

    foreach ($items as $item) {
        $statement->execute($item);
    }
}

function fetch_services(): array
{
    $tableName = services_table_name();
    $statement = db()->query(
        'SELECT
            id,
            slug,
            nome,
            cidade,
            descricao_curta,
            descricao_completa,
            valor_diaria,
            horas_disponiveis,
            limite_km,
            valor_hora_extra,
            valor_km_adicional,
            ordem_exibicao,
            ativo
         FROM ' . quote_identifier($tableName) . '
         ORDER BY ordem_exibicao ASC, id ASC'
    );

    return $statement->fetchAll();
}

function normalize_service_slug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function save_service(array $payload): array
{
    $tableName = services_table_name();
    $id = (int) ($payload['id'] ?? 0);
    $name = trim((string) ($payload['name'] ?? ''));
    $city = trim((string) ($payload['city'] ?? ''));
    $requestedSlug = trim((string) ($payload['slug'] ?? ''));
    $shortDescription = trim((string) ($payload['shortDescription'] ?? ''));
    $fullDescription = trim((string) ($payload['fullDescription'] ?? ''));
    $dailyRateRaw = str_replace(',', '.', trim((string) ($payload['dailyRate'] ?? '0')));
    $availableHours = (int) ($payload['availableHours'] ?? 0);
    $kmLimit = (int) ($payload['kmLimit'] ?? 0);
    $extraHourRateRaw = str_replace(',', '.', trim((string) ($payload['extraHourRate'] ?? '0')));
    $extraKmRateRaw = str_replace(',', '.', trim((string) ($payload['extraKmRate'] ?? '0')));
    $sortOrder = (int) ($payload['sortOrder'] ?? 0);
    $isActive = !empty($payload['isActive']) ? 1 : 0;
    $slug = normalize_service_slug($requestedSlug !== '' ? $requestedSlug : $name);

    if ($slug === '') {
        json_response(['ok' => false, 'error' => 'Informe um nome valido para gerar o identificador do plano.'], 422);
    }

    if ($name === '' || $city === '' || $shortDescription === '' || $fullDescription === '') {
        json_response(['ok' => false, 'error' => 'Preencha nome, cidade e descricoes do plano.'], 422);
    }

    if (!is_numeric($dailyRateRaw) || !is_numeric($extraHourRateRaw) || !is_numeric($extraKmRateRaw)) {
        json_response(['ok' => false, 'error' => 'Informe valores validos para diaria e adicionais do plano.'], 422);
    }

    if ($availableHours <= 0 || $kmLimit <= 0) {
        json_response(['ok' => false, 'error' => 'Informe horas disponiveis e limite de km maiores que zero.'], 422);
    }

    if ($sortOrder <= 0) {
        $maxOrderStatement = db()->query(
            'SELECT ISNULL(MAX(ordem_exibicao), 0) AS max_ordem FROM ' . quote_identifier($tableName)
        );
        $sortOrder = ((int) $maxOrderStatement->fetchColumn()) + 1;
    }

    $parameters = [
        'slug' => $slug,
        'nome' => $name,
        'cidade' => $city,
        'descricao_curta' => $shortDescription,
        'descricao_completa' => $fullDescription,
        'valor_diaria' => number_format((float) $dailyRateRaw, 2, '.', ''),
        'horas_disponiveis' => $availableHours,
        'limite_km' => $kmLimit,
        'valor_hora_extra' => number_format((float) $extraHourRateRaw, 2, '.', ''),
        'valor_km_adicional' => number_format((float) $extraKmRateRaw, 2, '.', ''),
        'ordem_exibicao' => $sortOrder,
        'ativo' => $isActive,
    ];

    $slugConflictStatement = db()->prepare(
        'SELECT TOP 1 id
         FROM ' . quote_identifier($tableName) . '
         WHERE slug = :slug
           AND (:id <= 0 OR id <> :id)'
    );
    $slugConflictStatement->execute([
        'slug' => $slug,
        'id' => $id,
    ]);

    if ($slugConflictStatement->fetch()) {
        json_response(['ok' => false, 'error' => 'Ja existe um plano com esse identificador.'], 422);
    }

    if ($id > 0) {
        $existsStatement = db()->prepare(
            'SELECT TOP 1 id
             FROM ' . quote_identifier($tableName) . '
             WHERE id = :id'
        );
        $existsStatement->execute(['id' => $id]);

        if (!$existsStatement->fetch()) {
            json_response(['ok' => false, 'error' => 'Plano nao encontrado para atualizacao.'], 404);
        }

        $statement = db()->prepare(
            'UPDATE ' . quote_identifier($tableName) . '
             SET slug = :slug,
                 nome = :nome,
                 cidade = :cidade,
                 descricao_curta = :descricao_curta,
                 descricao_completa = :descricao_completa,
                 valor_diaria = :valor_diaria,
                 horas_disponiveis = :horas_disponiveis,
                 limite_km = :limite_km,
                 valor_hora_extra = :valor_hora_extra,
                 valor_km_adicional = :valor_km_adicional,
                 ordem_exibicao = :ordem_exibicao,
                 ativo = :ativo,
                 updated_at = SYSUTCDATETIME()
             WHERE id = :id'
        );
        $statement->execute($parameters + ['id' => $id]);
    } else {
        $statement = db()->prepare(
            'INSERT INTO ' . quote_identifier($tableName) . ' (
                slug, nome, cidade, descricao_curta, descricao_completa, valor_diaria, horas_disponiveis, limite_km, valor_hora_extra, valor_km_adicional, ordem_exibicao, ativo, created_at, updated_at
            ) VALUES (
                :slug, :nome, :cidade, :descricao_curta, :descricao_completa, :valor_diaria, :horas_disponiveis, :limite_km, :valor_hora_extra, :valor_km_adicional, :ordem_exibicao, :ativo, SYSUTCDATETIME(), SYSUTCDATETIME()
            )'
        );
        $statement->execute($parameters);
        $id = (int) db()->lastInsertId();
    }

    $fetchStatement = db()->prepare(
        'SELECT TOP 1
            id,
            slug,
            nome,
            cidade,
            descricao_curta,
            descricao_completa,
            valor_diaria,
            horas_disponiveis,
            limite_km,
            valor_hora_extra,
            valor_km_adicional,
            ordem_exibicao,
            ativo
         FROM ' . quote_identifier($tableName) . '
         WHERE id = :id'
    );
    $fetchStatement->execute(['id' => $id]);

    $item = $fetchStatement->fetch();

    if (!$item) {
        json_response(['ok' => false, 'error' => 'Plano nao localizado apos salvar.'], 500);
    }

    return $item;
}

function leads_table_name(): string
{
    static $tableName = null;

    if (is_string($tableName) && $tableName !== '') {
        return $tableName;
    }

    $existing = find_table_by_suffix('solicitacoes_transporte_executivo');

    if ($existing !== null) {
        $tableName = $existing;
        return $tableName;
    }

    $nextSequence = next_tb_sequence();
    $candidate = 'tb' . $nextSequence . '_solicitacoes_transporte_executivo';
    $quotedTable = quote_identifier($candidate);
    $quotedStatusIndex = quote_identifier('ix_' . $candidate . '_status_data');
    $quotedPlanIndex = quote_identifier('ix_' . $candidate . '_plano');

    db()->exec(
        "CREATE TABLE {$quotedTable} (
            id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
            nome NVARCHAR(120) NOT NULL,
            telefone NVARCHAR(20) NOT NULL,
            origem NVARCHAR(255) NOT NULL,
            destino NVARCHAR(255) NOT NULL,
            data_viagem DATE NOT NULL,
            observacoes NVARCHAR(MAX) NULL,
            plano_slug NVARCHAR(30) NOT NULL,
            plano_nome NVARCHAR(60) NOT NULL,
            status_atendimento NVARCHAR(30) NOT NULL CONSTRAINT " . quote_identifier('df_' . $candidate . '_status') . " DEFAULT ('Novo') ,
            created_at DATETIME2(0) NOT NULL CONSTRAINT " . quote_identifier('df_' . $candidate . '_created_at') . " DEFAULT (SYSUTCDATETIME()),
            updated_at DATETIME2(0) NOT NULL CONSTRAINT " . quote_identifier('df_' . $candidate . '_updated_at') . " DEFAULT (SYSUTCDATETIME())
        )"
    );

    db()->exec("CREATE INDEX {$quotedStatusIndex} ON {$quotedTable} (status_atendimento, data_viagem, created_at)");
    db()->exec("CREATE INDEX {$quotedPlanIndex} ON {$quotedTable} (plano_slug, created_at)");

    $tableName = $candidate;
    return $tableName;
}

function fetch_service_map(): array
{
    $services = fetch_services();
    $map = [];

    foreach ($services as $service) {
        $map[strtolower((string) ($service['slug'] ?? ''))] = $service;
    }

    return $map;
}

function save_lead(array $payload): array
{
    $tableName = leads_table_name();
    $serviceMap = fetch_service_map();
    $name = trim((string) ($payload['name'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string) ($payload['phone'] ?? '')) ?? '';
    $origin = trim((string) ($payload['origin'] ?? ''));
    $destination = trim((string) ($payload['destination'] ?? ''));
    $travelDate = trim((string) ($payload['travelDate'] ?? ''));
    $notes = trim((string) ($payload['notes'] ?? ''));
    $planSlug = strtolower(trim((string) ($payload['planSlug'] ?? 'standard')));

    if ($name === '' || $phone === '' || $origin === '' || $destination === '' || $travelDate === '') {
        json_response(['ok' => false, 'error' => 'Preencha nome, telefone, origem, destino e data da viagem.'], 422);
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $travelDate)) {
        json_response(['ok' => false, 'error' => 'Informe a data da viagem em formato valido.'], 422);
    }

    if (!isset($serviceMap[$planSlug])) {
        json_response(['ok' => false, 'error' => 'Plano selecionado nao foi encontrado.'], 422);
    }

    $service = $serviceMap[$planSlug];
    $planName = (string) ($service['nome'] ?? 'Plano');

    $statement = db()->prepare(
        'INSERT INTO ' . quote_identifier($tableName) . ' (
            nome, telefone, origem, destino, data_viagem, observacoes, plano_slug, plano_nome, status_atendimento, created_at, updated_at
        ) VALUES (
            :nome, :telefone, :origem, :destino, :data_viagem, :observacoes, :plano_slug, :plano_nome, :status_atendimento, SYSUTCDATETIME(), SYSUTCDATETIME()
        )'
    );

    $statement->execute([
        'nome' => $name,
        'telefone' => $phone,
        'origem' => $origin,
        'destino' => $destination,
        'data_viagem' => $travelDate,
        'observacoes' => $notes !== '' ? $notes : null,
        'plano_slug' => $planSlug,
        'plano_nome' => $planName,
        'status_atendimento' => 'Novo',
    ]);

    $item = db()->query(
        'SELECT TOP 1
            id,
            nome,
            telefone,
            origem,
            destino,
            data_viagem,
            observacoes,
            plano_slug,
            plano_nome,
            status_atendimento,
            created_at
         FROM ' . quote_identifier($tableName) . '
         ORDER BY id DESC'
    )->fetch();

    if (!$item) {
        json_response(['ok' => false, 'error' => 'Falha ao localizar a solicitacao criada.'], 500);
    }

    return $item;
}

function fetch_leads(): array
{
    $tableName = leads_table_name();
    $status = trim((string) ($_GET['status'] ?? ''));
    $startDate = trim((string) ($_GET['start_date'] ?? ''));
    $endDate = trim((string) ($_GET['end_date'] ?? ''));
    $allowedStatuses = ['Novo', 'Em atendimento', 'Confirmado'];
    $conditions = [];
    $params = [];

    if ($status !== '') {
        if (!in_array($status, $allowedStatuses, true)) {
            json_response(['ok' => false, 'error' => 'Status de filtro invalido.'], 422);
        }

        $conditions[] = 'status_atendimento = :status_atendimento';
        $params['status_atendimento'] = $status;
    }

    if ($startDate !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            json_response(['ok' => false, 'error' => 'Data inicial invalida.'], 422);
        }

        $conditions[] = 'data_viagem >= :start_date';
        $params['start_date'] = $startDate;
    }

    if ($endDate !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            json_response(['ok' => false, 'error' => 'Data final invalida.'], 422);
        }

        $conditions[] = 'data_viagem <= :end_date';
        $params['end_date'] = $endDate;
    }

    $sql = 'SELECT
            id,
            nome,
            telefone,
            origem,
            destino,
            data_viagem,
            observacoes,
            plano_slug,
            plano_nome,
            status_atendimento,
            created_at
         FROM ' . quote_identifier($tableName);

    if ($conditions !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY created_at DESC, id DESC';

    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function update_lead_status(array $payload): array
{
    $tableName = leads_table_name();
    $id = (int) ($payload['id'] ?? 0);
    $status = trim((string) ($payload['status'] ?? ''));
    $allowedStatuses = ['Novo', 'Em atendimento', 'Confirmado'];

    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'Solicitacao invalida.'], 422);
    }

    if (!in_array($status, $allowedStatuses, true)) {
        json_response(['ok' => false, 'error' => 'Status invalido para a solicitacao.'], 422);
    }

    $statement = db()->prepare(
        'UPDATE ' . quote_identifier($tableName) . '
         SET status_atendimento = :status_atendimento,
             updated_at = SYSUTCDATETIME()
         WHERE id = :id'
    );
    $statement->execute([
        'status_atendimento' => $status,
        'id' => $id,
    ]);

    $fetchStatement = db()->prepare(
        'SELECT TOP 1
            id,
            nome,
            telefone,
            origem,
            destino,
            data_viagem,
            observacoes,
            plano_slug,
            plano_nome,
            status_atendimento,
            created_at
         FROM ' . quote_identifier($tableName) . '
         WHERE id = :id'
    );
    $fetchStatement->execute(['id' => $id]);

    $item = $fetchStatement->fetch();

    if (!$item) {
        json_response(['ok' => false, 'error' => 'Solicitacao nao encontrada.'], 404);
    }

    return $item;
}
