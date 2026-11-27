<?php
declare(strict_types=1);

require_once __DIR__.'/Format.php';
require_once __DIR__.'/VitrineClipboard.php';
require_once __DIR__.'/WorkspaceTripRowOrder.php';

function workspace_trip_date_key(mixed $raw): ?string {
    if (is_int($raw) || is_float($raw)) {
        $raw=display_cell(['effectiveValue'=>['numberValue'=>floor($raw)],
            'effectiveFormat'=>['numberFormat'=>['type'=>'DATE']]]);
    }
    if (!is_string($raw)) return null;
    $value=trim($raw);
    $date=vitrine_compact_input($value,'date');
    if ($date!==null && $date!=='') {
        [$day,$month,$year]=explode('.',$date);
        return "20$year-$month-$day";
    }
    // Some imported rows store a date range. Use its start only when a year is known.
    if (!preg_match('~^([0-9]{1,2})(?:[./]([0-9]{1,2})(?:[./]((?:20)?[0-9]{2}))?)?\.?\s*[-–—]\s*([0-9]{1,2})[./]([0-9]{1,2})(?:[./]((?:20)?[0-9]{2}))?\.?$~uD',$value,$match)) return null;
    [, $startDay,$startMonth,$startYear,$endDay,$endMonth]=$match;
    $endYear=$match[6]??'';
    if ($startYear==='' && $endYear==='') return null;
    $day=(int)$startDay;
    $month=(int)($startMonth!=='' ? $startMonth : $endMonth);
    $year=(int)($startYear!=='' ? $startYear : $endYear);
    if ($year<100) $year+=2000;
    $crossesYear=$month*100+$day > (int)$endMonth*100+(int)$endDay;
    if ($startYear==='' && $crossesYear) {
        if ($startMonth==='') return null;
        $year--;
    }
    $lastYear=$endYear==='' ? $year+(int)$crossesYear : (int)$endYear;
    if ($lastYear<100) $lastYear+=2000;
    if (!checkdate($month,$day,$year) || !checkdate((int)$endMonth,(int)$endDay,$lastYear)
        || $lastYear < $year+(int)$crossesYear) return null;
    return sprintf('%04d-%02d-%02d',$year,$month,$day);
}

function workspace_trip_date_group_key(mixed $raw): string {
    return workspace_trip_date_key($raw) ?? ($raw===null || (is_string($raw) && trim($raw)==='') ? 'undated' : 'ambiguous');
}

function workspace_trip_day_draft_date(int $row): ?string {
    if (!preg_match('/^-(20[0-9]{2})([0-9]{2})([0-9]{2})[12]$/D',(string)$row,$parts)) return null;
    [, $year, $month, $day]=$parts;
    return checkdate((int)$month,(int)$day,(int)$year) ? "$year-$month-$day" : null;
}

function workspace_trip_day_draft_value(array $table,int $row,mixed $anchor): ?string {
    if ($row>=-WORKSPACE_AVTOVYVOZ_DRAFT_ROWS) return null;
    $date=workspace_trip_day_draft_date($row);
    if ($table['slug']!=='vyvoz' || $date===null || !is_int($anchor) || $anchor<1) {
        throw new ApiError('invalid_trip_day','Проверьте дату новой строки рейса.');
    }
    workspace_require('field','F','view',$table['id']);
    workspace_require('field','F','edit',$table['id']);
    $raw=wquery("SELECT JSON_EXTRACT(values_json,'$.F') FROM records WHERE table_id=? AND row_num=? AND archived=0",
        [$table['id'],$anchor])->fetchColumn();
    if (!is_string($raw) || workspace_trip_date_key(json_decode($raw,true))!==$date) {
        throw new ApiError('trip_day_changed','Группа даты изменилась. Обновите таблицу и выберите строку снова.',409);
    }
    return substr($date,8,2).'.'.substr($date,5,2).'.'.substr($date,2,2);
}

function workspace_trip_date_group_label(string $key): string {
    if ($key==='ambiguous') return 'Дата загрузки не определена';
    if ($key==='undated') return 'Без даты загрузки';
    $date=new DateTimeImmutable($key,new DateTimeZone('Europe/Moscow'));
    $months=['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
    $weekdays=['Понедельник','Вторник','Среда','Четверг','Пятница','Суббота','Воскресенье'];
    return $date->format('j').' '.$months[(int)$date->format('n')-1].' '.$date->format('Y').' года ('.$weekdays[(int)$date->format('N')-1].')';
}

function workspace_trip_manifest_order(array $table,array $ids): array {
    if (!$ids || !workspace_can('field','F','view',$table['id'])) return ['rowIds'=>$ids,'tripDateGroups'=>[]];
    $placeholders=implode(',',array_fill(0,count($ids),'?'));
    $rows=wquery("SELECT row_num,JSON_EXTRACT(values_json,'$.F') AS load_date FROM records
        WHERE table_id=? AND archived=0 AND row_num IN ($placeholders) ORDER BY row_num",[$table['id'],...$ids]);
    $dates=[];
    $keys=[];
    while ($row=$rows->fetch()) {
        $raw=$row['load_date']??'null';
        $key=$keys[$raw]??=workspace_trip_date_group_key(json_decode($raw,true));
        $dates[$key][]=(int)$row['row_num'];
    }
    $ambiguous=$dates['ambiguous']??[];
    $undated=$dates['undated']??[];
    unset($dates['ambiguous'],$dates['undated']);
    ksort($dates,SORT_STRING);
    $dates=workspace_trip_row_order_apply($dates,$table['id']);
    $groups=['ambiguous'=>$ambiguous]+$dates+['undated'=>$undated];
    $markers=[];
    foreach ($groups as $key=>$group) {
        if ($group) $markers[]=['rowId'=>$group[0],'key'=>$key,'label'=>workspace_trip_date_group_label($key)];
    }
    return ['rowIds'=>array_merge(...array_values($groups)),'tripDateGroups'=>$markers];
}
