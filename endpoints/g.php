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

function map_gateway_service_item(array $item): array
{
    return [
        'id' => (int) ($item['id'] ?? 0),
        'slug' => (string) ($item['slug'] ?? ''),
        'name' => (string) ($item['nome'] ?? ''),
        'short_description' => (string) ($item['descricao_curta'] ?? ''),
        'full_description' => (string) ($item['descricao_completa'] ?? ''),
        'price' => (float) ($item['valor'] ?? 0),
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
        'notes' => (string) ($item['observacoes'] ?? ''),
        'plan_slug' => (string) ($item['plano_slug'] ?? ''),
        'plan_name' => (string) ($item['plano_nome'] ?? ''),
        'status' => (string) ($item['status_atendimento'] ?? 'Novo'),
        'created_at' => isset($item['created_at']) ? (string) $item['created_at'] : '',
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
            $today = date('Y-m-d');

            json_response([
                'ok' => $database['status'] === 'online',
                'environment' => current_environment(),
                'generated_at' => gmdate('c'),
                'database' => $database,
                'modules' => [
                    [
                        'slug' => 'landing-page',
                        'title' => 'Landing Page executiva',
                        'description' => 'Pagina comercial abastecida pelo cadastro dos planos Standard, Gold, Platinum e Black.',
                        'status_label' => 'Estrutura pronta',
                        'reference_date' => $today,
                    ],
                    [
                        'slug' => 'cadastro-servicos',
                        'title' => 'Cadastro de servicos',
                        'description' => 'Area administrativa para editar valores e descricoes dos planos de transporte executivo.',
                        'status_label' => 'Em andamento',
                        'reference_date' => $today,
                    ],
                    [
                        'slug' => 'captacao-comercial',
                        'title' => 'Captacao comercial',
                        'description' => 'Formulario da landing page gravando solicitacoes de interesse em transporte executivo.',
                        'status_label' => 'Lead tracking inicial pronto',
                        'reference_date' => $today,
                    ],
                ],
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
    json_response([
        'ok' => false,
        'error' => 'Payload do gateway nao informado.',
    ], 400);
}

dispatch_public_gateway_request($payload);
