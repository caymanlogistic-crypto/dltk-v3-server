<?php
declare(strict_types=1);

function workspace_fleet_trip_documents_can_view(int $tableId): bool {
    static $byTable=[];
    return $byTable[$tableId]??=(workspace_fleet_can_documents()
        && workspace_can('catalog',(string)workspace_fleet_catalog('fleet-drivers'),'view',$tableId)
        && workspace_can('field','R','view',$tableId));
}

function workspace_fleet_trip_documents_summary(int $driver): string {
    if (!$driver) return '';
    $data=workspace_fleet_documents($driver);
    $date=static fn(string $key): string=>empty($data[$key]) ? '' : implode('.',array_reverse(explode('-',$data[$key])));
    $parts=[];
    $passport=array_filter([$data['passport_number'], $data['passport_issuer'] ? 'выдан '.$data['passport_issuer'] : '',
        $date('passport_issued')],'strlen');
    if ($passport) $parts[]='Паспорт '.implode(', ',$passport);
    $license=array_filter([$data['license_number'], $date('license_issued') ? 'выдано '.$date('license_issued') : '',
        $date('license_expires') ? 'действует до '.$date('license_expires') : ''],'strlen');
    if ($license) $parts[]='ВУ '.implode(', ',$license);
    if ($date('birth_date')) $parts[]='Дата рождения '.$date('birth_date');
    return implode(' · ',$parts);
}

function workspace_fleet_trip_documents_values(array $recordIds): array {
    if (!$recordIds) return [];
    $slots=implode(',',array_fill(0,count($recordIds),'?'));
    return wquery("SELECT record_id,document_text FROM fleet_trip_documents WHERE record_id IN ($slots)",$recordIds)
        ->fetchAll(PDO::FETCH_KEY_PAIR);
}

function workspace_fleet_trip_documents_for_row(int $table,int $row,bool $archive): ?string {
    $record=wquery('SELECT d.document_text,IF(d.record_id IS NULL,r.baseline_json,NULL) AS baseline_json,
        IF(d.record_id IS NULL,r.metadata_zlib,NULL) AS metadata_zlib FROM records r
        LEFT JOIN fleet_trip_documents d ON d.record_id=r.id
        WHERE r.table_id=? AND r.row_num=? AND r.archived=?',[$table,$row,(int)$archive])->fetch();
    if (!$record) return null;
    return $record['document_text']??vitrine_catalog_original_value($record,'R');
}

function workspace_fleet_trip_documents_save(array $table,array $record,int $driver,bool $reset,
    string $action,string $request,string $operation): bool {
    $id=(int)$record['id'];
    $before=wquery('SELECT document_text FROM fleet_trip_documents WHERE record_id=?',[$id])->fetchColumn();
    if ($reset) {
        if ($before===false) return false;
        wquery('DELETE FROM fleet_trip_documents WHERE record_id=?',[$id]);
        $after=vitrine_catalog_original_value($record,'R');
    } else {
        $after=workspace_fleet_trip_documents_summary($driver);
        if ($after===$before) return false;
        wquery('INSERT INTO fleet_trip_documents(record_id,document_text) VALUES(?,?)
            ON DUPLICATE KEY UPDATE document_text=VALUES(document_text)',[$id,$after]);
    }
    if ($before===false) $before=vitrine_catalog_original_value($record,'R');
    workspace_audit(['tableId'=>$table['id'],'recordId'=>$id,'row'=>(int)$record['row_num'],'column'=>'R',
        'label'=>next_label($table['columns'],'R'),'before'=>['hasDocuments'=>$before!==''],
        'after'=>['hasDocuments'=>$after!==''],'action'=>$action,'requestId'=>$request,'operationId'=>$operation]);
    workspace_event('fleet.trip_documents.changed',['tableSlug'=>$table['slug'],'row'=>(int)$record['row_num'],'action'=>$action]);
    return true;
}
