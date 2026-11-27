<?php
declare(strict_types=1);

function workspace_terminal_tariff_grid(array $catalog): array {
    $labels=['A'=>'Терминал','B'=>'Раздел','C'=>'Услуга','D'=>'Контейнер','E'=>'Режим','F'=>'Условия',
        'G'=>'Ставка','H'=>'Ед.','I'=>'НДС','J'=>'С','K'=>'По'];
    $key='terminal-tariffs-'.(int)$catalog['catalogId'];
    $catalog['directoryKey']=$key;
    $catalog['slug']='catalog-'.$key;
    $catalog['columns']=array_map(static fn(string $letter,string $label): array => compact('letter','label'),
        array_keys($labels),array_values($labels));
    return $catalog;
}

function workspace_terminal_tariff_rows(array $tariffs): array {
    $date=static fn(string $value): string => (new DateTimeImmutable($value))->format('d.m.Y');
    $amount=static fn(int|float $value): string => number_format($value,(float)(int)$value===(float)$value ? 0 : 2,',',' ');
    $labels=['dash'=>'—','available'=>'Да','unavailable'=>'Нет','approval'=>'По согласованию','request'=>'По запросу'];
    $rows=[];
    foreach ($tariffs['rows'] as $index=>$row) {
        $price=$row['amount'];
        $rate=$price!==null ? $amount($price).' ₽' : ($row['freeDays']!==null
            ? $amount($row['freeDays']) : ($labels[$row['status']]??$row['raw']));
        $position=$tariffs['offset']+$index+1;
        $rows[]=['rowIndex'=>$position,'rowLabel'=>(string)$position,'baseHash'=>'','kinds'=>['G'=>'money'],
            'values'=>['A'=>$row['terminal'],'B'=>$row['sheet'],'C'=>$row['service'],
                'D'=>$row['containerType'] ?: ($row['size'] ? $row['size'].' футов' : ''),
                'E'=>$row['mode'],'F'=>implode(' · ',array_filter([$row['cargo'],$row['shipment'],$row['stage'],$row['condition']])),
                'G'=>$rate,'H'=>$row['unit'],'I'=>$price===null ? '' : $row['vat'].'%',
                'J'=>$date($row['from']),'K'=>$row['through'] ? $date($row['through']) : '']];
    }
    return $rows;
}

function workspace_terminal_tariffs(int $catalogId, array $filters): array {
    workspace_require('catalog',(string)$catalogId,'view');
    $string=static fn(string $key): string => is_string($filters[$key]??null) ? mb_substr(trim($filters[$key]),0,160) : '';
    $date=$string('tariffDate');
    if (!preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D',$date,$parts)
        || !checkdate((int)$parts[2],(int)$parts[3],(int)$parts[1])) {
        $date=(new DateTimeImmutable('today',new DateTimeZone('Europe/Moscow')))->format('Y-m-d');
    }
    $sheet=$string('tariffSheet');
    $terminal=$string('tariffTerminal');
    $size=$string('tariffSize');
    $query=$string('tariffQuery');
    $page=max(1,(int)$string('tariffPage'));
    $prefix='terminal_tariff_offer_';
    $offers=wquery('SELECT value FROM workspace_meta WHERE LEFT(`key`,?)=?',[strlen($prefix),$prefix])->fetchAll(PDO::FETCH_COLUMN);
    $labels=wquery('SELECT id,name FROM catalog_entries WHERE catalog_id=?',[$catalogId])->fetchAll(PDO::FETCH_KEY_PAIR);
    $versions=$terminalOptions=$sheetOptions=[];
    foreach ($offers as $value) {
        $offer=json_decode($value,true);
        if (($offer['version']??null)!==1 || (int)($offer['catalogId']??0)!==$catalogId) continue;
        foreach ($offer['groups'] as $group) {
            $id=$group['terminalId']??0;
            $terminalKey=isset($labels[$id]) ? (string)$id : 'КП: '.$group['label'];
            $terminalOptions[$terminalKey]=$labels[$id]??$group['label'].' (из КП)';
            $sheetOptions[$group['sheet']]=$group['sheet'];
            $versions[$group['key']][]=$group+['terminalKey'=>$terminalKey,'name'=>$labels[$id]??$group['label'],
                'sequence'=>$offer['sequence'],'vat'=>$offer['vat'],
                'vatIncluded'=>$offer['vatIncluded']];
        }
    }
    asort($terminalOptions,SORT_NATURAL);
    $active=[];
    foreach ($versions as $items) {
        usort($items,static fn(array $left,array $right): int =>
            [$right['effectiveFrom'],$right['sequence']] <=> [$left['effectiveFrom'],$left['sequence']]);
        $next=null;
        foreach ($items as $group) {
            if ($group['effectiveFrom']<=$date) {
                $group['through']=$next ? (new DateTimeImmutable($next))->modify('-1 day')->format('Y-m-d') : null;
                $active[]=$group;
                break;
            }
            $next=$group['effectiveFrom'];
        }
    }
    usort($active,static function(array $a,array $b): int {
        $order=['Терминалы'=>0,'ТЛЦ сухие'=>1,'Модуль'=>2,'ОПАСКА'=>3];
        $left=$order[$a['sheet']]??4;
        $right=$order[$b['sheet']]??4;
        return ($left<=>$right) ?: strnatcasecmp($a['name'],$b['name']);
    });
    $rows=[];
    foreach ($active as $group) {
        if (($sheet!=='' && $group['sheet']!==$sheet) || ($terminal!=='' && $group['terminalKey']!==$terminal)) continue;
        $items=$group['rows'];
        usort($items,static function(array $a,array $b): int {
            return ((int)ltrim($a['cell'],'ABCDEFGHIJKLMNOPQRSTUVWXYZ') <=>(int)ltrim($b['cell'],'ABCDEFGHIJKLMNOPQRSTUVWXYZ'))
                ?: (($a['size']??0) <=> ($b['size']??0));
        });
        foreach ($items as $row) {
            if ($size!=='' && (string)($row['size']??'')!==$size) continue;
            if ($query!=='' && mb_stripos(implode(' ',[$group['name'],$group['label'],$row['service'],$row['condition'],$row['mode'],$row['section'],$row['shipment'],$row['cargo'],
                $row['stage'],$row['containerType'],(string)$row['raw']]),$query)===false) continue;
            $rows[]=$row+['terminal'=>$group['name'],'sheet'=>$group['sheet'],
                'from'=>$group['effectiveFrom'],'through'=>$group['through'],
                'vat'=>$group['vat'],'vatIncluded'=>$group['vatIncluded']];
        }
    }
    $total=count($rows);
    $pageSize=60;
    $pages=max(1,(int)ceil($total/$pageSize));
    $page=min($page,$pages);
    $offset=($page-1)*$pageSize;
    $rows=array_slice($rows,$offset,$pageSize);
    return compact('date','sheet','terminal','size','query','page','pages','total','offset','rows','terminalOptions','sheetOptions');
}
