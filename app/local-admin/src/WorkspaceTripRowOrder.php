<?php
declare(strict_types=1);

function workspace_trip_row_order_prefix(int $tableId): string {
    return 'trip-row-order-'.$tableId.'-';
}

/** Retain ranked members of each date and append unranked members in row-number order. */
function workspace_trip_row_order_apply(array $groups,int $tableId): array {
    if (!$groups) return $groups;
    $prefix=workspace_trip_row_order_prefix($tableId);
    $orders=wquery('SELECT `key`,value FROM workspace_meta WHERE `key` LIKE ?',[$prefix.'%']);
    foreach ($orders as $order) {
        $date=substr($order['key'],strlen($prefix));
        if (!isset($groups[$date])) continue;
        $ranks=json_decode($order['value'],true);
        if (!is_array($ranks)) continue;
        $remaining=array_fill_keys($groups[$date],true);
        $ranked=[];
        foreach ($ranks as $row) {
            if (!is_int($row) || !isset($remaining[$row])) continue;
            $ranked[]=$row;
            unset($remaining[$row]);
        }
        $groups[$date]=[...$ranked,...array_keys($remaining)];
    }
    return $groups;
}

function workspace_trip_row_reorder(): never {
    if ($_SERVER['REQUEST_METHOD']!=='POST') throw new ApiError('method','Метод не поддерживается.',405);
    $table=workspace_table();
    if ($table['slug']!=='vyvoz' || vitrine_directory_requested()!==null || ($_GET['archive']??null)==='1') {
        throw new ApiError('forbidden','Перенос доступен в активном «Автовывозе».',403);
    }
    workspace_require('table',(string)$table['id'],'edit',$table['id']);
    workspace_require('field','F','view',$table['id']);
    $move=wbody();
    $source=$move['source']??null;
    $target=$move['target']??null;
    $after=$move['after']??null;
    $date=$move['date']??null;
    $requestId=$move['requestId']??null;
    if (!is_int($source) || $source<=1 || !is_int($target) || $target<=1 || $source===$target ||
        !is_bool($after) || !is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) ||
        !is_string($requestId) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$requestId)) {
        throw new ApiError('invalid_order','Выберите две разные строки одной даты.');
    }
    require_once __DIR__.'/WorkspaceTripDates.php';
    $changed=workspace_transaction(static function () use ($table,$source,$target,$after,$date,$requestId): bool {
        $records=wquery("SELECT r.id,r.row_num,JSON_EXTRACT(r.values_json,'$.F') AS trip_date
            FROM records r WHERE r.table_id=? AND r.archived=FALSE
              AND (r.metadata_zlib IS NULL OR REGEXP_LIKE(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(r.values_json,?)),''),'[^[:space:] ]')
                OR EXISTS (SELECT 1 FROM cell_versions v WHERE v.record_id=r.id AND v.field_key=? AND v.revision>0 AND v.value=''))
            ORDER BY r.row_num",[$table['id'],'$.'.$table['carrier_column'],$table['carrier_column']]);
        $group=[];
        $recordId=null;
        foreach ($records as $record) {
            $row=(int)$record['row_num'];
            $raw=$record['trip_date']===null ? null : json_decode($record['trip_date'],true);
            if (workspace_trip_date_key($raw)!==$date) continue;
            $group[]=$row;
            if ($row===$source) $recordId=(int)$record['id'];
        }
        if ($recordId===null || !in_array($target,$group,true)) {
            throw new ApiError('invalid_order','Строка или дата рейса изменилась. Обновите таблицу и повторите перенос.',409);
        }
        $current=workspace_trip_row_order_apply([$date=>$group],$table['id'])[$date];
        $next=array_values(array_filter($current,static fn(int $row): bool=>$row!==$source));
        $position=array_search($target,$next,true)+(int)$after;
        array_splice($next,$position,0,[$source]);
        if ($next===$current) return false;
        wquery('INSERT INTO workspace_meta(`key`,value) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
            [workspace_trip_row_order_prefix($table['id']).$date,wjson($next)]);
        $before=array_search($source,$current,true)+1;
        workspace_audit(['tableId'=>$table['id'],'recordId'=>$recordId,'row'=>$source,'label'=>'Порядок рейсов',
            'before'=>$before,'after'=>$position+1,'action'=>'reorder','category'=>'row','requestId'=>$requestId]);
        workspace_event('trip.row.reordered',['tableSlug'=>'vyvoz','row'=>$source,'target'=>$target,
            'direction'=>$position+1<=>$before,'requestId'=>$requestId]);
        return true;
    });
    json_response(['saved'=>true,'changed'=>$changed]);
}
