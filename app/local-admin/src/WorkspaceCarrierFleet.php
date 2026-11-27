<?php
declare(strict_types=1);

function workspace_carrier_fleet_entry(int $catalogId, int $entryId): array {
    workspace_require('catalog',(string)$catalogId,'view');
    $entry=wquery("SELECT e.id,e.name,e.active FROM catalog_entries e JOIN catalogs c ON c.id=e.catalog_id
        WHERE e.id=? AND e.catalog_id=? AND c.kind='carriers' AND NOT EXISTS (
            SELECT 1 FROM workspace_meta m WHERE m.`key`=CONCAT('catalog_deleted_client_',e.id))",
        [$entryId,$catalogId])->fetch();
    if (!$entry) throw new ApiError('not_found','Запись справочника не найдена.',404);
    return $entry;
}

function workspace_carrier_fleet_lists(array $state): array {
    $lists=[];
    foreach (['tractors','trailers'] as $type) {
        $lists[$type]=array_map(static fn(array $row): array=>['plate'=>$row['plate'],'brand'=>$row['brand']],$state[$type]??[]);
    }
    return $lists;
}

function workspace_carrier_fleet_card(int $catalogId, int $entryId): array {
    $entry=workspace_carrier_fleet_entry($catalogId,$entryId);
    $state=workspace_client_catalog_metadata('carrier_fleet_'.$entryId);
    $lists=workspace_carrier_fleet_lists($state);
    foreach ($lists as $type=>&$rows) {
        foreach ($rows as &$row) {
            $row['plate']=workspace_fleet_plate_format($row['plate'],$type==='trailers')??$row['plate'];
            $row['brand']=mb_strtoupper($row['brand']);
        }
        unset($row);
    }
    unset($rows);
    return ['id'=>$entryId,'name'=>$entry['name'],'legalName'=>$state['legalName']??'',
        'workingName'=>$state['workingName']??'','revision'=>(int)($state['revision']??0),
        'canEdit'=>(bool)$entry['active'] && workspace_can('catalog',(string)$catalogId,'edit')]+$lists;
}

function workspace_carrier_fleet_validate(mixed $rows, array $previous, bool $trailer): array {
    if (!is_array($rows) || !array_is_list($rows) || count($rows)>1000) {
        throw new ApiError('invalid_transport','Проверьте список транспорта.');
    }
    $old=[];
    foreach ($previous as $row) $old[workspace_fleet_plate_key($row['plate'])]=$row;
    $lists=[];
    $seen=[];
    foreach ($rows as $row) {
        if (!is_array($row)) throw new ApiError('invalid_transport','Проверьте строку транспорта.');
        $plate=workspace_unload_text($row['plate']??'',40);
        $brand=mb_strtoupper(workspace_unload_text($row['brand']??'',160));
        $key=workspace_fleet_plate_key($plate);
        $original=$old[$key]??null;
        if ($original) {
            $plate=$original['plate'];
            if ($brand===mb_strtoupper($original['brand'])) $brand=$original['brand'];
        } else {
            if ($trailer) {
                $valid=workspace_fleet_trailer_profile_key($plate)!=='';
            } else {
                $valid=preg_match('/^(?=[\p{L}\d]{4,14}$)(?=.*\d)(?=.*\p{L})[\p{L}\d]+$/uD',$key)
                    && workspace_fleet_trailer_key($plate)==='';
            }
            if (!$valid) throw new ApiError('invalid_transport',
                $trailer ? 'Проверьте госномер полуприцепа.' : 'Проверьте госномер тягача.');
            $plate=mb_strtoupper(workspace_fleet_plate_reorder($plate));
        }
        if (isset($seen[$key])) throw new ApiError('duplicate_transport','Госномер уже есть в этом списке.');
        $seen[$key]=true;
        $lists[]=compact('plate','brand');
    }
    return $lists;
}

function workspace_carrier_fleet_save(array $body): array {
    $catalogId=$body['catalogId']??null;
    $entryId=$body['entryId']??null;
    if (!is_int($catalogId) || !is_int($entryId)) throw new ApiError('invalid_edit','Выберите перевозчика.');
    workspace_require('catalog',(string)$catalogId,'edit');
    workspace_begin();
    try {
        $entry=workspace_carrier_fleet_entry($catalogId,$entryId);
        if (!$entry['active']) throw new ApiError('forbidden','Запись доступна только для просмотра.',403);
        $state=workspace_client_catalog_metadata('carrier_fleet_'.$entryId);
        $revision=(int)($state['revision']??0);
        if (($body['revision']??null)!==$revision) workspace_directory_conflict();
        $previous=workspace_carrier_fleet_lists($state);
        $lists=[];
        foreach ($previous as $type=>$rows) {
            $lists[$type]=workspace_carrier_fleet_validate($body[$type]??null,$rows,$type==='trailers');
        }
        if ($previous!==$lists) {
            $state=array_replace($state,$lists,['revision'=>$revision+1]);
            wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
                ['carrier_fleet_'.$entryId,wjson($state)]);
            workspace_audit(['category'=>'catalog','label'=>'Транспорт перевозчика','action'=>'edit',
                'before'=>['carrierId'=>$entryId]+$previous,'after'=>['carrierId'=>$entryId]+$lists]);
        }
        workspace_db()->commit();
        return workspace_carrier_fleet_card($catalogId,$entryId);
    } catch (Throwable $error) {
        if (workspace_db()->inTransaction()) workspace_db()->rollBack();
        throw $error;
    }
}
