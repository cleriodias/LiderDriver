<?php

declare(strict_types=1);

require __DIR__ . '/../../_bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $payload = input_json();
        $action = trim((string) ($payload['action'] ?? ''));
        $item = $action === 'update_status' ? update_lead_status($payload) : save_lead($payload);

        json_response([
            'ok' => true,
            'item' => [
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
            ],
        ], $action === 'update_status' ? 200 : 201);
    }

    $items = array_map(
        static fn(array $item): array => [
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
        ],
        fetch_leads()
    );

    json_response([
        'ok' => true,
        'items' => $items,
    ]);
} catch (Throwable $exception) {
    json_response([
        'ok' => false,
        'error' => 'Falha ao processar as solicitacoes da landing page.',
        'details' => $exception->getMessage(),
    ], 500);
}
