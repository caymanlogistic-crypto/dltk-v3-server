<?php
declare(strict_types=1);

require_once __DIR__.'/VitrineClipboard.php';
require_once __DIR__.'/WorkspaceVitrine.php';

function workspace_trip_search_ids(array $table,PDOStatement $records,string $query): array {
    $columns=array_filter($table['columns'],static fn(array $column): bool=>
        workspace_can('field',$column['letter'],'view',$table['id']) &&
        ($column['letter']!=='R' || workspace_fleet_trip_documents_can_view($table['id'])));
    $visible=array_fill_keys(array_column($columns,'letter'),true);
    $compact=vitrine_trip_compact_inputs($columns);
    $slots=array_fill_keys(array_column(array_filter($columns,static fn(array $column): bool=>
        mb_strtolower(trim($column['label']))==='слот'),'letter'),true);
    $links=vitrine_catalog_links($table);
    $phoneColumn=$links['O']['phoneTarget']??null;
    $margin=workspace_trip_margin_enabled($table);
    $unloadPoints=isset($links['K']) && (isset($visible['K']) || isset($visible['L'])) ? workspace_unload_points($links['K']['id']) : [];
    $dependentLinks=array_filter($links,static fn(array $link): bool=>isset($link['target'],$visible[$link['target']]) &&
        !in_array($link['kind'],['unload-points','fleet-vehicles'],true));
    $dependentValues=array_map('vitrine_catalog_values',$dependentLinks);
    [,,$names]=vitrine_catalog_options($table,array_intersect_key($dependentLinks,['B'=>true,'S'=>true]));
    $statuses=isset($visible['A']) ? array_column(workspace_status_catalog($table)['statuses'],'name','id') : [];
    $spaces=["\u{00A0}","\u{202F}"];
    $query=trim(str_replace($spaces,' ',$query));
    if ($query==='') return [];
    $rowNumber=ctype_digit($query) ? (int)$query : 0;
    $numeric=preg_match('/[0-9]/',$query)===1;
    $contains=static fn(string $value): bool=>$value!=='' && mb_stripos(str_replace($spaces,' ',$value),$query)!==false;
    $matches=[];
    while ($record=$records->fetch()) {
        $number=(int)$record['row_num'];
        if ($number===$rowNumber) {
            $matches[]=$number;
        } else {
            $values=json_decode($record['values_json'],true);
            $references=json_decode($record['cell_references']??'{}',true);
            if ($numeric && $margin && isset($visible['Z'])) $values['Z']=workspace_trip_margin($values);
            $status=$statuses[(int)$record['status_id']]??null;
            if ($status!==null) $values['A']=$status;
            $point=$unloadPoints[$references['K']['id']??0]??null;
            if ($point!==null) {
                $values['K']=$point['address'];
                $values['L']=workspace_unload_primary($point);
            }
            if ($record['documents']!==null) $values['R']=$record['documents'];
            foreach ($references as $field=>$reference) {
                if (!in_array($field,['K','O','P','Q','R'],true)) {
                    $values[$field]=vitrine_catalog_reference_label($reference,$links[$field]??null);
                }
            }
            vitrine_apply_catalog_dependents($values,$references,$dependentLinks,$names,$dependentValues);
            foreach (array_intersect_key($values,$visible) as $field=>$raw) {
                if ($raw==='' || $raw===null) continue;
                $value=workspace_string($raw);
                if ($contains($value)) {
                    $matches[]=$number;
                    break;
                }
                $display=match (true) {
                    isset($slots[$field])=>vitrine_slot_input($value)??$value,
                    $numeric && $field===$phoneColumn=>workspace_fleet_trip_phone_display($value),
                    $numeric && $margin && in_array($field,['X','Y'],true)=>($amount=workspace_trip_amount_minor($raw))!==null
                        ? workspace_trip_amount_label($amount) : $value,
                    $numeric && isset($compact[$field])=>vitrine_compact_display($value,$compact[$field]),
                    default=>$value,
                };
                if ($display!==$value && $contains($display)) {
                    $matches[]=$number;
                    break;
                }
            }
        }
        if (count($matches)===WORKSPACE_MANIFEST_LIMIT) break;
    }
    return $matches;
}
