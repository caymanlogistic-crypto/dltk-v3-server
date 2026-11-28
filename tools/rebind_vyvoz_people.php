<?php
declare(strict_types=1);

require_once __DIR__.'/../app/local-admin/src/bootstrap.php';
require_once __DIR__.'/../app/local-admin/src/Format.php';
require_once __DIR__.'/../app/local-admin/src/WorkspaceVitrineCatalogLinks.php';

function rebind_trip_people_sources(): array {
    $tableId=(int)wquery("SELECT id FROM table_definitions WHERE slug='vyvoz'")->fetchColumn();
    $table=['id'=>$tableId,'slug'=>'vyvoz'];
    $links=array_intersect_key(vitrine_catalog_links($table),['B'=>true,'S'=>true]);
    if (!$tableId || count($links)!==2 || !workspace_client_account_managers_enabled()) {
        throw new RuntimeException('Prepare the client account migration and the client and carrier catalog bindings first.');
    }
    return [$tableId,$links];
}

function rebind_trip_people_source_text(array $record,array $values,array $baseline,array $raw,string $field): string {
    $original=display_cell(vitrine_cell_metadata($raw,$field));
    $version=$record[$field.'_active'];
    if ($version!==null) {
        return workspace_string($version ? $record[$field.'_edit'] : ($original??$baseline[$field]??''));
    }
    return workspace_string($original??$values[$field]??'');
}

function rebind_trip_people_rows(int $tableId): Generator {
    $lastId=0;
    do {
        $rows=wquery("SELECT r.id,r.row_num,r.archived,r.metadata_zlib,r.baseline_json,r.values_json,
        bc.entry_id B_ref,be.catalog_id B_catalog,sv.entry_id S_ref,se.catalog_id S_catalog,
        bv.active B_active,bv.value B_edit,cv.active S_active,cv.value S_edit
        FROM records r
        LEFT JOIN record_references bc ON bc.record_id=r.id AND bc.field_key='B'
        LEFT JOIN catalog_entries be ON be.id=bc.entry_id
        LEFT JOIN record_references sv ON sv.record_id=r.id AND sv.field_key='S'
        LEFT JOIN catalog_entries se ON se.id=sv.entry_id
        LEFT JOIN cell_versions bv ON bv.record_id=r.id AND bv.field_key='B'
        LEFT JOIN cell_versions cv ON cv.record_id=r.id AND cv.field_key='S'
        WHERE r.table_id=? AND r.id>? ORDER BY r.id LIMIT 250",[$tableId,$lastId])->fetchAll();
        foreach ($rows as $row) {
            $lastId=(int)$row['id'];
            yield $row;
        }
    } while ($rows);
}

function rebind_trip_people_pass(array $links,int $tableId,bool $apply): array {
    $names=[];
    foreach ($links as $field=>$link) $names[$field]=vitrine_trip_people_catalog_names($link['id']);
    $dependent=array_map('vitrine_catalog_values',$links);
    $stats=['rows'=>0,'archivedRows'=>0,'changedRows'=>0,'references'=>[]];
    foreach (array_keys($links) as $field) {
        $stats['references'][$field]=['existing'=>0,'added'=>0,'unmatched'=>0,'empty'=>0];
    }
    $misses=[];
    $snapshot=[];
    foreach (rebind_trip_people_rows($tableId) as $row) {
        ++$stats['rows'];
        $stats['archivedRows']+=(int)$row['archived'];
        $values=json_decode($row['values_json'],true,512,JSON_THROW_ON_ERROR);
        $hasSourceText=!isset($row['B_ref'],$row['S_ref']);
        $baseline=$hasSourceText ? json_decode($row['baseline_json'],true,512,JSON_THROW_ON_ERROR) : [];
        $raw=$hasSourceText && $row['metadata_zlib'] ? blob_json($row['metadata_zlib']) : [];
        $additions=[];
        $derived=[];
        $updatedValues=[];
        foreach ($links as $field=>$link) {
            $existing=(int)($row[$field.'_ref']??0);
            $reference=$existing ? ['id'=>$existing,'catalogId'=>(int)$row[$field.'_catalog']] : null;
            $text=$reference ? '' : rebind_trip_people_source_text($row,$values,$baseline,$raw,$field);
            if ($reference && $reference['catalogId']!==$link['id']) {
                throw new RuntimeException("Unexpected catalog on $field of trip ".$row['row_num'].'.');
            }
            $source=vitrine_trip_people_source_id($link,$reference,$names[$field],$text);
            if ($existing) {
                ++$stats['references'][$field]['existing'];
            } elseif ($source) {
                ++$stats['references'][$field]['added'];
                $additions[$field]=$source;
            } elseif (trim($text)!=='') {
                ++$stats['references'][$field]['unmatched'];
                $misses[]=['row'=>(int)$row['row_num'],'field'=>$field,'value'=>$text,'archived'=>(bool)$row['archived']];
            } else {
                ++$stats['references'][$field]['empty'];
            }
            $target=$link['target'];
            $next=$dependent[$field][$source]??'';
            if (($values[$target]??null)!==$next) {
                $updatedValues[$target]=$next;
                $derived[$target]=['present'=>array_key_exists($target,$values),'value'=>$values[$target]??null];
            }
        }
        if (!$additions && !$derived) continue;
        ++$stats['changedRows'];
        if (!$apply) continue;
        $snapshot[$row['id']]=['insertedReferences'=>$additions,'previousValues'=>$derived];
        foreach ($additions as $field=>$id) {
            wquery('INSERT INTO record_references(record_id,field_key,entry_id) VALUES(?,?,?)',[$row['id'],$field,$id]);
        }
        if ($updatedValues) {
            $arguments=[];
            foreach ($updatedValues as $field=>$value) array_push($arguments,'$.'.$field,$value);
            $slots=implode(',',array_fill(0,count($arguments),'?'));
            wquery("UPDATE records SET values_json=JSON_SET(values_json,$slots),updated_at=updated_at WHERE id=?",
                [...$arguments,$row['id']]);
        }
    }
    return [$stats,$misses,$snapshot];
}

function rebind_trip_people_log(array $stats,array $snapshot,array $misses,int $tableId): string {
    $operation=bin2hex(random_bytes(12));
    $dir=rtrim(runtime_config()['PRIVATE_DIR'],'/\\');
    $receipt=$dir.'/vyvoz-account-bindings-'.$operation.'.json';
    if (file_put_contents($receipt,wjson(['tableId'=>$tableId,'operation'=>$operation,'before'=>$snapshot,'unmatched'=>$misses]))===false) {
        throw new RuntimeException('Could not save the recovery snapshot.');
    }
    wquery("INSERT INTO change_history(actor_name,table_id,column_label,after_value,action,category,operation_id)
        VALUES(?,?,?,?,'edit','catalog',?)",
        ['Перенос назначений на учётные записи',$tableId,'Менеджеры и логисты',wjson($stats),$operation]);
    return $operation;
}

if (PHP_SAPI!=='cli') exit(1);
$mode=$argv[1]??'';
if (count($argv)>2 || !in_array($mode,['','--apply'],true)) {
    fwrite(STDERR,"Usage: php tools/rebind_vyvoz_people.php [--apply]\n");
    exit(1);
}
try {
    $run=static function () use ($mode): array {
        [$tableId,$links]=rebind_trip_people_sources();
        [$stats,$misses,$snapshot]=rebind_trip_people_pass($links,$tableId,$mode==='--apply');
        if ($snapshot) $stats['operation']=rebind_trip_people_log($stats,$snapshot,$misses,$tableId);
        return $stats;
    };
    $db=workspace_db();
    if ($mode==='--apply') {
        $result=workspace_transaction($run);
    } else {
        $db->exec('START TRANSACTION READ ONLY');
        try {
            $result=$run();
        } finally {
            $db->rollBack();
        }
    }
    echo wjson($result),PHP_EOL;
} catch (Throwable $error) {
    $message=$error instanceof ApiError ? $error->publicMessage : $error->getMessage();
    fwrite(STDERR,$message.PHP_EOL);
    exit(1);
}

