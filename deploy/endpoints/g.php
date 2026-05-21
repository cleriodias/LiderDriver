<?php

declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';

function decode_gateway_request_payload(string $payload): array
{
    $payload = trim($payload);

    if ($payload === '') {
        return [];
    }

    $data = json_decode($payload, true);
    return is_array($data) ? $data : [];
}

function request_param(string $key, string $default = ''): string
{
    if (isset($_POST[$key])) {
        return trim((string) $_POST[$key]);
    }

    if (isset($_GET[$key])) {
        return trim((string) $_GET[$key]);
    }

    return $default;
}

function decode_simple_gateway_payload(): array
{
    $action = strtolower(request_param('a'));

    if ($action === '') {
        return [];
    }

    $data = [];

    foreach ($_POST as $key => $value) {
        if ($key === 'a' || $key === 'q') {
            continue;
        }

        $data[(string) $key] = $value;
    }

    foreach ($_GET as $key => $value) {
        if ($key === 'a' || $key === 'q') {
            continue;
        }

        if (!array_key_exists((string) $key, $data)) {
            $data[(string) $key] = $value;
        }
    }

    return [
        'a' => $action,
        'd' => $data,
    ];
}

function map_gateway_service_item(array $item): array
{
    return [
        'id' => (int) ($item['id'] ?? 0),
        'slug' => (string) ($item['slug'] ?? ''),
        'name' => (string) ($item['nome'] ?? ''),
        'city' => (string) ($item['cidade'] ?? ''),
        'short_description' => (string) ($item['descricao_curta'] ?? ''),
        'full_description' => (string) ($item['descricao_completa'] ?? ''),
        'daily_rate' => (float) ($item['valor_diaria'] ?? 0),
        'available_hours' => (int) ($item['horas_disponiveis'] ?? 0),
        'km_limit' => (int) ($item['limite_km'] ?? 0),
        'extra_hour_rate' => (float) ($item['valor_hora_extra'] ?? 0),
        'extra_km_rate' => (float) ($item['valor_km_adicional'] ?? 0),
        'sort_order' => (int) ($item['ordem_exibicao'] ?? 0),
        'is_active' => (bool) ($item['ativo'] ?? false),
    ];
}

function map_gateway_lead_item(array $item): array
{
    return [
        'id' => (int) ($item['id'] ?? 0),
        'name' => (string) ($item['nome'] ?? ''),
        'phone' => (string) ($item['telefone'] ?? ''),
        'origin' => (string) ($item['origem'] ?? ''),
        'destination' => (string) ($item['destino'] ?? ''),
        'travel_date' => (string) ($item['data_viagem'] ?? ''),
        'travel_time' => (string) ($item['hora_inicio'] ?? ''),
        'notes' => (string) ($item['observacoes'] ?? ''),
        'plan_slug' => (string) ($item['plano_slug'] ?? ''),
        'plan_name' => (string) ($item['plano_nome'] ?? ''),
        'status' => (string) ($item['status_atendimento'] ?? 'Novo'),
        'created_at' => isset($item['created_at']) ? (string) $item['created_at'] : '',
        'requester_email' => (string) ($item['solicitante_email'] ?? ''),
        'requester_name' => (string) ($item['solicitante_nome'] ?? ''),
        'requester_auth_provider' => (string) ($item['solicitante_auth_provider'] ?? ''),
        'driver_email' => (string) ($item['motorista_email'] ?? ''),
        'driver_name' => (string) ($item['motorista_nome'] ?? ''),
        'captured_at' => isset($item['capturado_em']) ? (string) $item['capturado_em'] : '',
        'started_at' => isset($item['iniciado_em']) ? (string) $item['iniciado_em'] : '',
        'finished_at' => isset($item['finalizado_em']) ? (string) $item['finalizado_em'] : '',
        'cancelled_at' => isset($item['cancelado_em']) ? (string) $item['cancelado_em'] : '',
        'cancelled_by_email' => (string) ($item['cancelado_por_email'] ?? ''),
        'cancelled_by_name' => (string) ($item['cancelado_por_nome'] ?? ''),
        'cancellation_reason' => (string) ($item['motivo_cancelamento'] ?? ''),
    ];
}

function dispatch_public_gateway_request(array $payload): void
{
    $action = trim((string) ($payload['a'] ?? ''));
    $data = isset($payload['d']) && is_array($payload['d']) ? $payload['d'] : [];

    switch ($action) {
        case 'dbs':
            $database = database_health();
            $status = $database['status'] === 'online' ? 200 : 500;

            json_response([
                'ok' => $database['status'] === 'online',
                'environment' => current_environment(),
                'generated_at' => gmdate('c'),
                'database' => $database,
                'lead_summary' => fetch_lead_status_summary(),
                'modules' => [],
            ], $status);

        case 'svl':
            json_response([
                'ok' => true,
                'items' => array_map('map_gateway_service_item', fetch_services()),
            ]);

        case 'svs':
            json_response([
                'ok' => true,
                'item' => map_gateway_service_item(save_service($data)),
            ]);

        case 'ldl':
            $previousQuery = $_GET;
            $_GET = [];

            if (isset($data['status']) && trim((string) $data['status']) !== '' && (string) $data['status'] !== 'all') {
                $_GET['status'] = (string) $data['status'];
            }

            if (isset($data['startDate']) && trim((string) $data['startDate']) !== '') {
                $_GET['start_date'] = (string) $data['startDate'];
            }

            if (isset($data['endDate']) && trim((string) $data['endDate']) !== '') {
                $_GET['end_date'] = (string) $data['endDate'];
            }

            if (isset($data['requesterEmail']) && trim((string) $data['requesterEmail']) !== '') {
                $_GET['requester_email'] = (string) $data['requesterEmail'];
            }

            if (isset($data['driverEmail']) && trim((string) $data['driverEmail']) !== '') {
                $_GET['driver_email'] = (string) $data['driverEmail'];
            }

            try {
                $items = array_map('map_gateway_lead_item', fetch_leads());
            } finally {
                $_GET = $previousQuery;
            }

            json_response([
                'ok' => true,
                'items' => $items,
            ]);

        case 'lds':
            json_response([
                'ok' => true,
                'item' => map_gateway_lead_item(save_lead($data)),
            ], 201);

        case 'ldt':
            json_response([
                'ok' => true,
                'item' => map_gateway_lead_item(take_lead_for_driver($data)),
            ]);

        case 'ldc':
            json_response([
                'ok' => true,
                'item' => map_gateway_lead_item(cancel_lead($data)),
            ]);

        case 'ldu':
            json_response([
                'ok' => true,
                'item' => map_gateway_lead_item(update_lead_status($data)),
            ]);
    }

    json_response([
        'ok' => false,
        'error' => 'Acao de gateway invalida.',
    ], 422);
}

$payload = decode_gateway_request_payload((string) ($_POST['q'] ?? ''));

if ($payload === []) {
    $payload = decode_simple_gateway_payload();
}

if ($payload === []) {
    json_response([
        'ok' => false,
        'error' => 'Payload do gateway nao informado.',
    ], 400);
}

dispatch_public_gateway_request($payload);
