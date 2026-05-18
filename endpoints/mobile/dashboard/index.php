<?php

declare(strict_types=1);

require __DIR__ . '/../../_bootstrap.php';

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
