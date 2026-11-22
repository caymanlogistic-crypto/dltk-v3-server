<?php
declare(strict_types=1);

function workspace_client_order_statuses(): array {
    return [
        'preliminary'=>['id'=>1,'label'=>'Предварительный заказ','color'=>'#F7F2D2'],
        'confirmed'=>['id'=>2,'label'=>'Подтверждённый заказ','color'=>'#DCEAD4'],
    ];
}

function workspace_client_order_status(string $code): array {
    return workspace_client_order_statuses()[$code] ?? ['label'=>'','color'=>''];
}

function workspace_client_order_status_can_view(array $table): bool {
    return $table['slug']==='vyvoz' && workspace_can('system','client-order-statuses','view',$table['id']);
}
