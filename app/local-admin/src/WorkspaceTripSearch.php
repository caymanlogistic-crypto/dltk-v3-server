<?php
declare(strict_types=1);

require_once __DIR__.'/VitrineClipboard.php';
require_once __DIR__.'/WorkspaceVitrine.php';
require_once __DIR__.'/WorkspaceTripDates.php';

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
    $canJoin=!str_contains($query,"\0");
    $personFields=['AL'=>true,'AM'=>true];
    $formattedFields=$slots+$personFields;
    if ($numeric) {
        $formattedFields+=array_fill_keys(array_keys(array_filter($compact,static fn($kind)=>$kind!=='container')),true);
        if ($phoneColumn!==null) $formattedFields[$phoneColumn]=true;
        if ($margin) $formattedFields+=['X'=>true,'Y'=>true];
    }
    $formattedFields=array_intersect_key($formattedFields,$visible);
    $formattedMatches=[];
    $pointContacts=[];
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
            $pointId=$references['K']['id']??0;
            $point=$unloadPoints[$pointId]??null;
            if ($point!==null) {
                $values['K']=$point['address'];
                $values['L']=$pointContacts[$pointId]??=workspace_unload_primary($point);
            }
            if ($record['documents']!==null) $values['R']=$record['documents'];
            foreach ($references as $field=>$reference) {
                if (!in_array($field,['K','O','P','Q','R'],true)) {
                    $values[$field]=vitrine_catalog_reference_label($reference,$links[$field]??null);
                }
            }
            vitrine_apply_catalog_dependents($values,$references,$dependentLinks,$names,$dependentValues);
            $rawValues=array_intersect_key($values,$visible);
            $matched=$contains(workspace_string($rawValues['A']??'')) || $contains(workspace_string($rawValues['B']??''));
            $strings=[];
            if (!$matched) {
                $strings=array_map(static fn($value): string=>is_string($value) ? $value : workspace_string($value),$rawValues);
                // The separator cannot occur in the query, so a match cannot cross cell boundaries.
                if ($canJoin) {
                    $matched=$contains(implode("\0",$strings));
                } else {
                    foreach ($strings as $value) {
                        if ($contains($value)) {
                            $matched=true;
                            break;
                        }
                    }
                }
            }
            if ($matched) {
                $matches[]=$number;
            }
            foreach ($matched ? [] : array_intersect_key($rawValues,$formattedFields) as $field=>$raw) {
                if ($raw==='' || $raw===null) continue;
                $value=$strings[$field];
                $cacheKey=gettype($raw).':'.$value;
                $shownMatches=$formattedMatches[$field][$cacheKey]??null;
                if ($shownMatches===null) {
                    $display=match (true) {
                        isset($personFields[$field])=>workspace_account_short_names($value),
                        isset($slots[$field])=>vitrine_slot_input($value)??$value,
                        $field===$phoneColumn=>workspace_fleet_trip_phone_display($value),
                        $margin && in_array($field,['X','Y'],true)=>($amount=workspace_trip_amount_minor($raw))!==null
                            ? workspace_trip_amount_label($amount) : $value,
                        default=>vitrine_compact_display($value,$compact[$field]),
                    };
                    $shownMatches=$display!==$value && $contains($display);
                    if (!$shownMatches && $field==='F' && ($compact[$field]??null)==='date' &&
                        (is_int($raw) || is_float($raw)) && ($date=workspace_trip_date_key($raw))!==null) {
                        $shownMatches=$contains(vitrine_compact_display($date,'date'));
                    }
                    if (strlen($value)<=128 && count($formattedMatches[$field]??[])<1024) {
                        $formattedMatches[$field][$cacheKey]=$shownMatches;
                    }
                }
                if ($shownMatches) {
                    $matches[]=$number;
                    break;
                }
            }
        }
        if (count($matches)===WORKSPACE_MANIFEST_LIMIT) break;
    }
    return $matches;
}
