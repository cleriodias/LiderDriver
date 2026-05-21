<?php

declare(strict_types=1);

require __DIR__ . '/../../_bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $payload = input_json();
        $action = trim((string) ($payload['action'] ?? ''));
        $item = match ($action) {
            'update_status' => update_lead_status($payload),
            'take_for_driver' => take_lead_for_driver($payload),
            'cancel' => cancel_lead($payload),
            default => save_lead($payload),
        };

        json_response([
            'ok' => true,
            'item' => [
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
            ],
        ], $action === 'update_status' || $action === 'take_for_driver' || $action === 'cancel' ? 200 : 201);
    }

    $items = array_map(
        static fn(array $item): array => [
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
