<?php

declare(strict_types=1);

require __DIR__ . '/../../_bootstrap.php';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        $item = save_service(input_json());

        json_response([
            'ok' => true,
            'item' => [
                'id' => (int) ($item['id'] ?? 0),
                'slug' => (string) ($item['slug'] ?? ''),
                'name' => (string) ($item['nome'] ?? ''),
                'short_description' => (string) ($item['descricao_curta'] ?? ''),
                'full_description' => (string) ($item['descricao_completa'] ?? ''),
                'price' => (float) ($item['valor'] ?? 0),
                'sort_order' => (int) ($item['ordem_exibicao'] ?? 0),
                'is_active' => (bool) ($item['ativo'] ?? false),
            ],
        ]);
    }

    $items = array_map(
        static fn(array $item): array => [
            'id' => (int) ($item['id'] ?? 0),
            'slug' => (string) ($item['slug'] ?? ''),
            'name' => (string) ($item['nome'] ?? ''),
            'short_description' => (string) ($item['descricao_curta'] ?? ''),
            'full_description' => (string) ($item['descricao_completa'] ?? ''),
            'price' => (float) ($item['valor'] ?? 0),
            'sort_order' => (int) ($item['ordem_exibicao'] ?? 0),
            'is_active' => (bool) ($item['ativo'] ?? false),
        ],
        fetch_services()
    );

    json_response([
        'ok' => true,
        'items' => $items,
    ]);
} catch (Throwable $exception) {
    json_response([
        'ok' => false,
        'error' => 'Falha ao carregar os servicos da landing page.',
        'details' => $exception->getMessage(),
    ], 500);
}
