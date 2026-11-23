<?php
declare(strict_types=1);

function workspace_client_unload_count_label(int $count): string {
    $last=$count%10;
    $teen=$count%100>=11 && $count%100<=14;
    $word=match (true) {
        !$teen && $last===1 => 'точка',
        !$teen && $last>=2 && $last<=4 => 'точки',
        default => 'точек',
    };
    return $count.' '.$word.' выгрузки';
}

function workspace_client_unload_counts(int $catalogId): array {
    $review=workspace_unload_review_ids($catalogId);
    $counts=[];
    $rows=wquery('SELECT e.id,p.client_id FROM catalog_entries e JOIN unload_points p ON p.entry_id=e.id
        WHERE e.catalog_id=? AND e.active=TRUE AND p.client_id IS NOT NULL',[$catalogId])->fetchAll();
    foreach ($rows as $row) {
        if (!isset($review[$row['id']])) $counts[$row['client_id']]=($counts[$row['client_id']]??0)+1;
    }
    return $counts;
}

function workspace_client_unload_points(int $clientId,?array $body=null): array {
    $clientCatalog=(int)(workspace_client_grid_catalog()['id']??0);
    $catalogId=workspace_unload_catalog_id();
    if (!$clientCatalog || !$catalogId) throw new ApiError('not_found','Справочник не найден.',404);
    workspace_require('catalog',(string)$catalogId,$body===null?'view':'edit');
    $client=wquery('SELECT name,active FROM catalog_entries WHERE catalog_id=? AND id=?',[$clientCatalog,$clientId])->fetch();
    if (!$client) throw new ApiError('not_found','Клиент не найден.',404);
    $canEdit=$client['active'] && workspace_can('catalog',(string)$catalogId,'edit');
    $list=static fn(): array=>array_filter(workspace_unload_points($catalogId,$clientId),
        static fn(array $point): bool=>(bool)$point['active'] && !$point['review_required']);
    if ($body!==null) {
        if (!$canEdit) throw new ApiError('forbidden','Точки этого клиента доступны только для просмотра.',403);
        $entryId=$body['entryId']??null;
        $revision=$body['revision']??null;
        if ($entryId!==null && (!is_int($entryId) || !is_int($revision))) {
            throw new ApiError('invalid_edit','Проверьте данные точки выгрузки.');
        }
        $point=$entryId!==null ? ($list()[$entryId]??null) : null;
        if ($entryId!==null && !$point) throw new ApiError('not_found','Точка клиента не найдена.',404);
        if ($body['action']==='save_client_unload_point') {
            $contact=workspace_unload_text($body['contact']??'',6000);
            if ($contact==='' && (!$point || workspace_unload_contact_line(workspace_unload_primary($point))!=='')) {
                throw new ApiError('invalid_unload_contact','Укажите контакты точки выгрузки.');
            }
            workspace_unload_save_point($catalogId,['B'=>$body['address']??null],$entryId,$revision,$clientId,$contact);
        } elseif ($body['action']==='delete_client_unload_point' && $point) {
            workspace_catalog_save(['catalogId'=>$catalogId,'id'=>$entryId,'revision'=>$revision,
                'name'=>$point['name'],'active'=>false],workspace_row_delete_reason($body['reason']??null));
        } else {
            throw new ApiError('invalid_action','Выберите точку выгрузки.');
        }
    }
    $points=[];
    foreach ($list() as $point) {
        $points[]=['id'=>(int)$point['id'],'address'=>$point['address'],'revision'=>(int)$point['revision'],
            'contact'=>workspace_unload_contact_line(workspace_unload_primary($point))];
    }
    return ['name'=>$client['name'],'canEdit'=>$canEdit,'points'=>$points,
        'summary'=>workspace_client_unload_count_label(count($points))];
}
