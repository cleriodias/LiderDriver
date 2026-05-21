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

function administrative_emails(): array
{
    return [
        'grandeantoniomarcio@gmail.com',
        'flaviocaynabenjamim@gmail.com',
        'abraaobighand@gmail.com',
        'cleriodias@gmail.com',
    ];
}

function is_administrative_email(string $email): bool
{
    return in_array(strtolower(trim($email)), administrative_emails(), true);
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

function column_exists(string $tableName, string $columnName): bool
{
    $statement = db()->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $statement->execute([
        'table_name' => $tableName,
        'column_name' => $columnName,
    ]);

    return (bool) $statement->fetchColumn();
}

function index_exists(string $tableName, string $indexName): bool
{
    $statement = db()->prepare(
        'SELECT 1
         FROM sys.indexes
         WHERE object_id = OBJECT_ID(:table_name)
           AND name = :index_name'
    );
    $statement->execute([
        'table_name' => $tableName,
        'index_name' => $indexName,
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
        ensure_leads_table_structure($tableName);
        return $tableName;
    }

    $nextSequence = next_tb_sequence();
    $candidate = 'tb' . $nextSequence . '_solicitacoes_transporte_executivo';
    $quotedTable = quote_identifier($candidate);
    $quotedStatusIndex = quote_identifier('ix_' . $candidate . '_status_data_hora');
    $quotedPlanIndex = quote_identifier('ix_' . $candidate . '_plano');
    $quotedRequesterIndex = quote_identifier('ix_' . $candidate . '_solicitante_email_created');
    $quotedDriverScheduleIndex = quote_identifier('ix_' . $candidate . '_motorista_agenda');

    db()->exec(
        "CREATE TABLE {$quotedTable} (
            id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
            nome NVARCHAR(120) NOT NULL,
            telefone NVARCHAR(20) NOT NULL,
            origem NVARCHAR(255) NOT NULL,
            destino NVARCHAR(255) NOT NULL,
            data_viagem DATE NOT NULL,
            hora_inicio CHAR(5) NOT NULL,
            horas_previstas INT NOT NULL,
            observacoes NVARCHAR(MAX) NULL,
            plano_slug NVARCHAR(30) NOT NULL,
            plano_nome NVARCHAR(60) NOT NULL,
            solicitante_email NVARCHAR(255) NULL,
            solicitante_nome NVARCHAR(160) NULL,
            solicitante_auth_provider NVARCHAR(20) NULL,
            motorista_email NVARCHAR(255) NULL,
            motorista_nome NVARCHAR(160) NULL,
            capturado_em DATETIME2(0) NULL,
            iniciado_em DATETIME2(0) NULL,
            finalizado_em DATETIME2(0) NULL,
            cancelado_em DATETIME2(0) NULL,
            cancelado_por_email NVARCHAR(255) NULL,
            cancelado_por_nome NVARCHAR(160) NULL,
            motivo_cancelamento NVARCHAR(500) NULL,
            status_atendimento NVARCHAR(30) NOT NULL CONSTRAINT " . quote_identifier('df_' . $candidate . '_status') . " DEFAULT ('Novo') ,
            created_at DATETIME2(0) NOT NULL CONSTRAINT " . quote_identifier('df_' . $candidate . '_created_at') . " DEFAULT (SYSUTCDATETIME()),
            updated_at DATETIME2(0) NOT NULL CONSTRAINT " . quote_identifier('df_' . $candidate . '_updated_at') . " DEFAULT (SYSUTCDATETIME())
        )"
    );

    db()->exec("CREATE INDEX {$quotedStatusIndex} ON {$quotedTable} (status_atendimento, data_viagem, hora_inicio, created_at)");
    db()->exec("CREATE INDEX {$quotedPlanIndex} ON {$quotedTable} (plano_slug, created_at)");
    db()->exec("CREATE INDEX {$quotedRequesterIndex} ON {$quotedTable} (solicitante_email, created_at)");
    db()->exec("CREATE INDEX {$quotedDriverScheduleIndex} ON {$quotedTable} (motorista_email, data_viagem, hora_inicio, status_atendimento)");

    $tableName = $candidate;
    ensure_leads_table_structure($tableName);
    return $tableName;
}

function ensure_leads_table_structure(string $tableName): void
{
    $quotedTable = quote_identifier($tableName);
    $requesterIndexName = 'ix_' . $tableName . '_solicitante_email_created';
    $statusIndexName = 'ix_' . $tableName . '_status_data_hora';
    $driverScheduleIndexName = 'ix_' . $tableName . '_motorista_agenda';

    if (!column_exists($tableName, 'solicitante_email')) {
        db()->exec('ALTER TABLE ' . $quotedTable . ' ADD solicitante_email NVARCHAR(255) NULL');
    }

    if (!column_exists($tableName, 'solicitante_nome')) {
        db()->exec('ALTER TABLE ' . $quotedTable . ' ADD solicitante_nome NVARCHAR(160) NULL');
    }

    if (!column_exists($tableName, 'solicitante_auth_provider')) {
        db()->exec('ALTER TABLE ' . $quotedTable . ' ADD solicitante_auth_provider NVARCHAR(20) NULL');
    }

    if (!column_exists($tableName, 'hora_inicio')) {
        db()->exec('ALTER TABLE ' . $quotedTable . " ADD hora_inicio CHAR(5) NOT NULL CONSTRAINT " . quote_identifier('df_' . $tableName . '_hora_inicio') . " DEFAULT ('08:00')");
    }

    if (!column_exists($tableName, 'horas_previstas')) {
        db()->exec('ALTER TABLE ' . $quotedTable . " ADD horas_previstas INT NOT NULL CONSTRAINT " . quote_identifier('df_' . $tableName . '_horas_previstas') . ' DEFAULT (1)');
    }

    if (!column_exists($tableName, 'motorista_email')) {
        db()->exec('ALTER TABLE ' . $quotedTable . ' ADD motorista_email NVARCHAR(255) NULL');
    }

    if (!column_exists($tableName, 'motorista_nome')) {
        db()->exec('ALTER TABLE ' . $quotedTable . ' ADD motorista_nome NVARCHAR(160) NULL');
    }

    if (!column_exists($tableName, 'capturado_em')) {
        db()->exec('ALTER TABLE ' . $quotedTable . ' ADD capturado_em DATETIME2(0) NULL');
    }

    if (!column_exists($tableName, 'iniciado_em')) {
        db()->exec('ALTER TABLE ' . $quotedTable . ' ADD iniciado_em DATETIME2(0) NULL');
    }

    if (!column_exists($tableName, 'finalizado_em')) {
        db()->exec('ALTER TABLE ' . $quotedTable . ' ADD finalizado_em DATETIME2(0) NULL');
    }

    if (!column_exists($tableName, 'cancelado_em')) {
        db()->exec('ALTER TABLE ' . $quotedTable . ' ADD cancelado_em DATETIME2(0) NULL');
    }

    if (!column_exists($tableName, 'cancelado_por_email')) {
        db()->exec('ALTER TABLE ' . $quotedTable . ' ADD cancelado_por_email NVARCHAR(255) NULL');
    }

    if (!column_exists($tableName, 'cancelado_por_nome')) {
        db()->exec('ALTER TABLE ' . $quotedTable . ' ADD cancelado_por_nome NVARCHAR(160) NULL');
    }

    if (!column_exists($tableName, 'motivo_cancelamento')) {
        db()->exec('ALTER TABLE ' . $quotedTable . ' ADD motivo_cancelamento NVARCHAR(500) NULL');
    }

    if (!index_exists($tableName, $requesterIndexName)) {
        db()->exec(
            'CREATE INDEX ' . quote_identifier($requesterIndexName) . ' ON ' . $quotedTable . ' (solicitante_email, created_at)'
        );
    }

    if (!index_exists($tableName, $statusIndexName)) {
        db()->exec(
            'CREATE INDEX ' . quote_identifier($statusIndexName) . ' ON ' . $quotedTable . ' (status_atendimento, data_viagem, hora_inicio, created_at)'
        );
    }

    if (!index_exists($tableName, $driverScheduleIndexName)) {
        db()->exec(
            'CREATE INDEX ' . quote_identifier($driverScheduleIndexName) . ' ON ' . $quotedTable . ' (motorista_email, data_viagem, hora_inicio, status_atendimento)'
        );
    }
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

function normalize_time_value(string $value): string
{
    $trimmed = trim($value);

    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $trimmed, $matches)) {
        return '';
    }

    $hour = (int) $matches[1];
    $minute = (int) $matches[2];

    if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
        return '';
    }

    return sprintf('%02d:%02d', $hour, $minute);
}

function lead_select_columns(): string
{
    return 'id,
            nome,
            telefone,
            origem,
            destino,
            data_viagem,
            hora_inicio,
            horas_previstas,
            observacoes,
            plano_slug,
            plano_nome,
            solicitante_email,
            solicitante_nome,
            solicitante_auth_provider,
            motorista_email,
            motorista_nome,
            capturado_em,
            iniciado_em,
            finalizado_em,
            cancelado_em,
            cancelado_por_email,
            cancelado_por_nome,
            motivo_cancelamento,
            status_atendimento,
            created_at,
            updated_at';
}

function fetch_lead_by_id(string $tableName, int $id): ?array
{
    $statement = db()->prepare(
        'SELECT TOP 1
            ' . lead_select_columns() . '
         FROM ' . quote_identifier($tableName) . '
         WHERE id = :id'
    );
    $statement->execute(['id' => $id]);

    $item = $statement->fetch();
    return is_array($item) ? $item : null;
}

function fetch_lead_by_id_for_update(string $tableName, int $id): ?array
{
    $statement = db()->prepare(
        'SELECT TOP 1
            ' . lead_select_columns() . '
         FROM ' . quote_identifier($tableName) . ' WITH (UPDLOCK, HOLDLOCK)
         WHERE id = :id'
    );
    $statement->execute(['id' => $id]);

    $item = $statement->fetch();
    return is_array($item) ? $item : null;
}

function build_lead_schedule_range(string $travelDate, string $travelTime, int $durationHours): array
{
    $timezone = new DateTimeZone('America/Sao_Paulo');
    $start = DateTimeImmutable::createFromFormat('Y-m-d H:i', $travelDate . ' ' . $travelTime, $timezone);

    if (!$start instanceof DateTimeImmutable) {
        throw new RuntimeException('Nao foi possivel interpretar a data e hora da solicitacao.', 422);
    }

    $minutes = max($durationHours, 1) * 60;
    $end = $start->modify('+' . $minutes . ' minutes');

    return [$start, $end];
}

function validate_driver_schedule_conflict(
    string $tableName,
    int $leadId,
    string $driverEmail,
    string $travelDate,
    string $travelTime,
    int $durationHours
): void {
    [$requestedStart, $requestedEnd] = build_lead_schedule_range($travelDate, $travelTime, $durationHours);
    $statement = db()->prepare(
        'SELECT
            l.id,
            l.nome,
            l.data_viagem,
            l.hora_inicio,
            l.plano_nome,
            ISNULL(l.horas_previstas, 1) AS horas_previstas
         FROM ' . quote_identifier($tableName) . ' AS l
         WHERE l.motorista_email = :motorista_email
           AND l.data_viagem = :data_viagem
           AND l.id <> :id
           AND l.status_atendimento IN (\'Em atendimento\', \'Em servico\')'
    );
    $statement->execute([
        'motorista_email' => $driverEmail,
        'data_viagem' => $travelDate,
        'id' => $leadId,
    ]);

    foreach ($statement->fetchAll() as $item) {
        $existingTime = normalize_time_value((string) ($item['hora_inicio'] ?? ''));

        if ($existingTime === '') {
            continue;
        }

        $existingHours = max((int) ($item['horas_previstas'] ?? 0), 1);
        [$existingStart, $existingEnd] = build_lead_schedule_range(
            (string) ($item['data_viagem'] ?? ''),
            $existingTime,
            $existingHours
        );

        if ($requestedStart < $existingEnd && $existingStart < $requestedEnd) {
            throw new RuntimeException(
                'Esse motorista ja possui atendimento em conflito com a solicitacao #' . (int) ($item['id'] ?? 0) .
                ' as ' . $existingTime . ' do dia ' . (string) ($item['data_viagem'] ?? '') . '.',
                409
            );
        }
    }
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
    $travelTime = normalize_time_value((string) ($payload['travelTime'] ?? ''));
    $notes = trim((string) ($payload['notes'] ?? ''));
    $planSlug = strtolower(trim((string) ($payload['planSlug'] ?? 'standard')));
    $requesterEmail = strtolower(trim((string) ($payload['requesterEmail'] ?? '')));
    $requesterName = trim((string) ($payload['requesterName'] ?? ''));
    $requesterAuthProvider = trim((string) ($payload['requesterAuthProvider'] ?? ''));

    if ($name === '' || $phone === '' || $origin === '' || $destination === '' || $travelDate === '' || $travelTime === '') {
        json_response(['ok' => false, 'error' => 'Preencha nome, telefone, origem, destino, data e hora da viagem.'], 422);
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $travelDate)) {
        json_response(['ok' => false, 'error' => 'Informe a data da viagem em formato valido.'], 422);
    }

    if ($travelTime === '') {
        json_response(['ok' => false, 'error' => 'Informe a hora da viagem em formato valido.'], 422);
    }

    if (!isset($serviceMap[$planSlug])) {
        json_response(['ok' => false, 'error' => 'Plano selecionado nao foi encontrado.'], 422);
    }

    if ($requesterEmail !== '' && !filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) {
        json_response(['ok' => false, 'error' => 'Email do solicitante invalido.'], 422);
    }

    if ($requesterAuthProvider !== '' && !in_array($requesterAuthProvider, ['email', 'google'], true)) {
        json_response(['ok' => false, 'error' => 'Provedor de autenticacao do solicitante invalido.'], 422);
    }

    $service = $serviceMap[$planSlug];
    $planName = (string) ($service['nome'] ?? 'Plano');
    $plannedHours = max((int) ($service['horas_disponiveis'] ?? 0), 1);

    $statement = db()->prepare(
        'INSERT INTO ' . quote_identifier($tableName) . ' (
            nome, telefone, origem, destino, data_viagem, hora_inicio, horas_previstas, observacoes, plano_slug, plano_nome, solicitante_email, solicitante_nome, solicitante_auth_provider, status_atendimento, created_at, updated_at
        ) VALUES (
            :nome, :telefone, :origem, :destino, :data_viagem, :hora_inicio, :horas_previstas, :observacoes, :plano_slug, :plano_nome, :solicitante_email, :solicitante_nome, :solicitante_auth_provider, :status_atendimento, SYSUTCDATETIME(), SYSUTCDATETIME()
        )'
    );

    $statement->execute([
        'nome' => $name,
        'telefone' => $phone,
        'origem' => $origin,
        'destino' => $destination,
        'data_viagem' => $travelDate,
        'hora_inicio' => $travelTime,
        'horas_previstas' => $plannedHours,
        'observacoes' => $notes !== '' ? $notes : null,
        'plano_slug' => $planSlug,
        'plano_nome' => $planName,
        'solicitante_email' => $requesterEmail !== '' ? $requesterEmail : null,
        'solicitante_nome' => $requesterName !== '' ? $requesterName : null,
        'solicitante_auth_provider' => $requesterAuthProvider !== '' ? $requesterAuthProvider : null,
        'status_atendimento' => 'Novo',
    ]);

    $item = db()->query(
        'SELECT TOP 1
            ' . lead_select_columns() . '
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
    $requesterEmail = strtolower(trim((string) ($_GET['requester_email'] ?? '')));
    $driverEmail = strtolower(trim((string) ($_GET['driver_email'] ?? '')));
    $allowedStatuses = ['Novo', 'Em atendimento', 'Em servico', 'Finalizado', 'Cancelado'];
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

    if ($requesterEmail !== '') {
        if (!filter_var($requesterEmail, FILTER_VALIDATE_EMAIL)) {
            json_response(['ok' => false, 'error' => 'Email do solicitante invalido.'], 422);
        }

        $conditions[] = 'solicitante_email = :solicitante_email';
        $params['solicitante_email'] = $requesterEmail;
    }

    if ($driverEmail !== '') {
        if (!filter_var($driverEmail, FILTER_VALIDATE_EMAIL)) {
            json_response(['ok' => false, 'error' => 'Email do motorista invalido.'], 422);
        }

        $conditions[] = 'motorista_email = :motorista_email';
        $params['motorista_email'] = $driverEmail;
    }

    $sql = 'SELECT
            ' . lead_select_columns() . '
         FROM ' . quote_identifier($tableName);

    if ($conditions !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY data_viagem ASC, hora_inicio ASC, created_at DESC, id DESC';

    $statement = db()->prepare($sql);
    $statement->execute($params);

    return $statement->fetchAll();
}

function take_lead_for_driver(array $payload): array
{
    $tableName = leads_table_name();
    $id = (int) ($payload['id'] ?? 0);
    $driverEmail = strtolower(trim((string) ($payload['driverEmail'] ?? '')));
    $driverName = trim((string) ($payload['driverName'] ?? ''));

    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'Solicitacao invalida.'], 422);
    }

    if ($driverEmail === '' || !filter_var($driverEmail, FILTER_VALIDATE_EMAIL)) {
        json_response(['ok' => false, 'error' => 'Email do motorista invalido.'], 422);
    }

    if ($driverName === '') {
        json_response(['ok' => false, 'error' => 'Nome do motorista obrigatorio.'], 422);
    }

    $connection = db();

    try {
        $connection->beginTransaction();
        $lead = fetch_lead_by_id_for_update($tableName, $id);

        if ($lead === null) {
            throw new RuntimeException('Solicitacao nao encontrada.', 404);
        }

        $currentDriverEmail = strtolower(trim((string) ($lead['motorista_email'] ?? '')));
        $currentStatus = trim((string) ($lead['status_atendimento'] ?? 'Novo'));
        $travelDate = trim((string) ($lead['data_viagem'] ?? ''));
        $travelTime = normalize_time_value((string) ($lead['hora_inicio'] ?? ''));

        if (in_array($currentStatus, ['Finalizado', 'Cancelado'], true)) {
            throw new RuntimeException('Essa solicitacao nao pode mais ser assumida.', 409);
        }

        if ($currentStatus === 'Em servico') {
            throw new RuntimeException('Essa solicitacao ja esta com servico iniciado.', 409);
        }

        if ($currentDriverEmail !== '' && $currentDriverEmail !== $driverEmail) {
            throw new RuntimeException('Essa solicitacao ja foi assumida por outro motorista.', 409);
        }

        if ($travelDate === '' || $travelTime === '') {
            throw new RuntimeException('A solicitacao nao possui data e hora validas para roteirizacao.', 422);
        }

        $durationHours = max((int) ($lead['horas_previstas'] ?? 0), 1);
        validate_driver_schedule_conflict($tableName, $id, $driverEmail, $travelDate, $travelTime, $durationHours);

        $statement = $connection->prepare(
            'UPDATE ' . quote_identifier($tableName) . '
             SET motorista_email = :motorista_email,
                 motorista_nome = :motorista_nome,
                 capturado_em = ISNULL(capturado_em, SYSUTCDATETIME()),
                 iniciado_em = NULL,
                 finalizado_em = NULL,
                 cancelado_em = NULL,
                 cancelado_por_email = NULL,
                 cancelado_por_nome = NULL,
                 motivo_cancelamento = NULL,
                 status_atendimento = :status_atendimento,
                 updated_at = SYSUTCDATETIME()
             WHERE id = :id'
        );
        $statement->execute([
            'motorista_email' => $driverEmail,
            'motorista_nome' => $driverName,
            'status_atendimento' => 'Em atendimento',
            'id' => $id,
        ]);

        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        if ($exception instanceof RuntimeException && $exception->getCode() >= 400) {
            json_response(['ok' => false, 'error' => $exception->getMessage()], $exception->getCode());
        }

        throw $exception;
    }

    $item = fetch_lead_by_id($tableName, $id);

    if ($item === null) {
        json_response(['ok' => false, 'error' => 'Solicitacao nao encontrada apos captura.'], 404);
    }

    return $item;
}

function cancel_lead(array $payload): array
{
    $tableName = leads_table_name();
    $id = (int) ($payload['id'] ?? 0);
    $actorEmail = strtolower(trim((string) ($payload['actorEmail'] ?? '')));
    $actorName = trim((string) ($payload['actorName'] ?? ''));
    $reason = trim((string) ($payload['reason'] ?? ''));

    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'Solicitacao invalida.'], 422);
    }

    if ($actorEmail === '' || !filter_var($actorEmail, FILTER_VALIDATE_EMAIL)) {
        json_response(['ok' => false, 'error' => 'Email do operador invalido para cancelamento.'], 422);
    }

    if ($actorName === '') {
        json_response(['ok' => false, 'error' => 'Nome do operador obrigatorio para cancelamento.'], 422);
    }

    $connection = db();

    try {
        $connection->beginTransaction();
        $lead = fetch_lead_by_id_for_update($tableName, $id);

        if ($lead === null) {
            throw new RuntimeException('Solicitacao nao encontrada.', 404);
        }

        $currentStatus = trim((string) ($lead['status_atendimento'] ?? 'Novo'));
        $currentDriverEmail = strtolower(trim((string) ($lead['motorista_email'] ?? '')));
        $isAdministrativeActor = is_administrative_email($actorEmail);

        if ($currentStatus === 'Cancelado') {
            throw new RuntimeException('Essa solicitacao ja foi cancelada.', 409);
        }

        if ($currentStatus === 'Finalizado') {
            throw new RuntimeException('Uma solicitacao finalizada nao pode ser cancelada.', 409);
        }

        if ($currentStatus === 'Em servico') {
            throw new RuntimeException('Um servico ja iniciado nao pode ser cancelado por este fluxo.', 409);
        }

        if ($currentDriverEmail === '') {
            if (!$isAdministrativeActor) {
                throw new RuntimeException('Apenas um administrador autorizado pode cancelar uma solicitacao sem motorista responsavel.', 403);
            }
        } elseif ($actorEmail !== $currentDriverEmail && !$isAdministrativeActor) {
            throw new RuntimeException('Apenas o motorista responsavel ou um administrador autorizado pode cancelar esta solicitacao.', 403);
        }

        if ($reason === '') {
            $reason = $actorEmail === $currentDriverEmail
                ? 'Cancelado pelo motorista responsavel.'
                : 'Cancelado pelo administrador.';
        }

        $statement = $connection->prepare(
            'UPDATE ' . quote_identifier($tableName) . '
             SET status_atendimento = :status_atendimento,
                 cancelado_em = ISNULL(cancelado_em, SYSUTCDATETIME()),
                 cancelado_por_email = :cancelado_por_email,
                 cancelado_por_nome = :cancelado_por_nome,
                 motivo_cancelamento = :motivo_cancelamento,
                 updated_at = SYSUTCDATETIME()
             WHERE id = :id'
        );
        $statement->execute([
            'status_atendimento' => 'Cancelado',
            'cancelado_por_email' => $actorEmail,
            'cancelado_por_nome' => $actorName,
            'motivo_cancelamento' => $reason,
            'id' => $id,
        ]);

        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }

        if ($exception instanceof RuntimeException && $exception->getCode() >= 400) {
            json_response(['ok' => false, 'error' => $exception->getMessage()], $exception->getCode());
        }

        throw $exception;
    }

    $item = fetch_lead_by_id($tableName, $id);

    if ($item === null) {
        json_response(['ok' => false, 'error' => 'Solicitacao nao encontrada apos cancelamento.'], 404);
    }

    return $item;
}

function update_lead_status(array $payload): array
{
    $tableName = leads_table_name();
    $id = (int) ($payload['id'] ?? 0);
    $status = trim((string) ($payload['status'] ?? ''));
    $actorEmail = strtolower(trim((string) ($payload['actorEmail'] ?? '')));
    $allowedStatuses = ['Novo', 'Em atendimento', 'Em servico', 'Finalizado'];

    if ($id <= 0) {
        json_response(['ok' => false, 'error' => 'Solicitacao invalida.'], 422);
    }

    if (!in_array($status, $allowedStatuses, true)) {
        json_response(['ok' => false, 'error' => 'Status invalido para a solicitacao.'], 422);
    }

    $item = fetch_lead_by_id($tableName, $id);

    if ($item === null) {
        json_response(['ok' => false, 'error' => 'Solicitacao nao encontrada.'], 404);
    }

    $currentDriverEmail = strtolower(trim((string) ($item['motorista_email'] ?? '')));
    $currentStatus = trim((string) ($item['status_atendimento'] ?? 'Novo'));

    if ($currentStatus === 'Cancelado') {
        json_response([
            'ok' => false,
            'error' => 'Uma solicitacao cancelada nao pode ter o status alterado.',
        ], 409);
    }

    if ($currentDriverEmail === '' && $status !== 'Novo') {
        json_response([
            'ok' => false,
            'error' => 'A solicitacao precisa ser assumida por um motorista antes de iniciar o atendimento.',
        ], 422);
    }

    if ($currentDriverEmail !== '') {
        if ($actorEmail === '' || !filter_var($actorEmail, FILTER_VALIDATE_EMAIL)) {
            json_response(['ok' => false, 'error' => 'Email do motorista responsavel invalido.'], 422);
        }

        if ($actorEmail !== $currentDriverEmail) {
            json_response([
                'ok' => false,
                'error' => 'Apenas o motorista responsavel pode alterar o status desta solicitacao.',
            ], 403);
        }

        if ($currentStatus !== 'Novo' && $status === 'Novo') {
            json_response([
                'ok' => false,
                'error' => 'Uma solicitacao assumida nao pode voltar para o status Novo.',
            ], 422);
        }
    }

    $allowedTransitions = [
        'Novo' => ['Novo'],
        'Em atendimento' => ['Em servico'],
        'Em servico' => ['Finalizado'],
        'Finalizado' => ['Finalizado'],
    ];

    $allowedTargets = $allowedTransitions[$currentStatus] ?? [];

    if ($currentStatus !== $status && !in_array($status, $allowedTargets, true)) {
        json_response([
            'ok' => false,
            'error' => 'Transicao de status invalida para esta solicitacao.',
        ], 422);
    }

    $setParts = [
        'status_atendimento = :status_atendimento',
        'updated_at = SYSUTCDATETIME()',
    ];
    $params = [
        'status_atendimento' => $status,
        'id' => $id,
    ];

    if ($currentStatus !== $status && $status === 'Em servico') {
        $setParts[] = 'iniciado_em = ISNULL(iniciado_em, SYSUTCDATETIME())';
    }

    if ($currentStatus !== $status && $status === 'Finalizado') {
        $setParts[] = 'finalizado_em = ISNULL(finalizado_em, SYSUTCDATETIME())';
    }

    $statement = db()->prepare(
        'UPDATE ' . quote_identifier($tableName) . '
         SET ' . implode(', ', $setParts) . '
         WHERE id = :id'
    );
    $statement->execute($params);

    $updatedLead = fetch_lead_by_id($tableName, $id);

    if ($updatedLead === null) {
        json_response(['ok' => false, 'error' => 'Solicitacao nao encontrada.'], 404);
    }

    return $updatedLead;
}

function fetch_lead_status_summary(): array
{
    $tableName = leads_table_name();
    $statement = db()->query(
        'SELECT status_atendimento, COUNT(*) AS total
         FROM ' . quote_identifier($tableName) . '
         GROUP BY status_atendimento'
    );

    $summary = [
        'total' => 0,
        'novo' => 0,
        'em_atendimento' => 0,
        'em_servico' => 0,
        'finalizado' => 0,
        'cancelado' => 0,
    ];

    foreach ($statement->fetchAll() as $item) {
        $status = trim((string) ($item['status_atendimento'] ?? ''));
        $total = (int) ($item['total'] ?? 0);
        $summary['total'] += $total;

        if ($status === 'Novo') {
            $summary['novo'] = $total;
            continue;
        }

        if ($status === 'Em atendimento') {
            $summary['em_atendimento'] = $total;
            continue;
        }

        if ($status === 'Em servico') {
            $summary['em_servico'] = $total;
            continue;
        }

        if ($status === 'Finalizado') {
            $summary['finalizado'] = $total;
            continue;
        }

        if ($status === 'Cancelado') {
            $summary['cancelado'] = $total;
        }
    }

    return $summary;
}
