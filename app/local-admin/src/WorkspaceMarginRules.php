<?php
declare(strict_types=1);
require_once __DIR__.'/VitrineClipboard.php';

function workspace_margin_calendar_date(mixed $value): ?string {
    if (!is_string($value)) return null;
    $value=trim($value);
    if (preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D',$value,$parts)) {
        [, $year,$month,$day]=$parts;
    } elseif (preg_match('/^([0-9]{1,2})\.([0-9]{1,2})\.([0-9]{2}|[0-9]{4})$/D',$value,$parts)) {
        [, $day,$month,$year]=$parts;
        if (strlen($year)===2) $year='20'.$year;
    } else return null;
    return checkdate((int)$month,(int)$day,(int)$year) ? sprintf('%04d-%02d-%02d',$year,$month,$day) : null;
}

function workspace_margin_rule_date(mixed $value): string {
    return workspace_margin_calendar_date($value)
        ?? throw new ApiError('invalid_margin_rule_date','Укажите дату начала в формате ДД.ММ.ГГГГ.');
}

function workspace_margin_rule_date_label(string $iso): string {
    return implode('.',array_reverse(explode('-',$iso)));
}

function workspace_margin_rule_profile(mixed $values): array {
    if (!is_array($values)) throw new ApiError('invalid_margin_rule','Заполните оба минимума.');
    $percent=$values['B']??'';
    if (!is_string($percent) || !preg_match('/^([0-9]{1,3})(?:[.,]([0-9]{1,2}))?\s*%?$/D',trim($percent),$parts)) {
        throw new ApiError('invalid_margin_percent','Укажите процент от 0 до 100, до двух знаков после запятой.');
    }
    $basis=(int)$parts[1]*100+(int)str_pad($parts[2]??'',2,'0');
    if ($basis>10000) throw new ApiError('invalid_margin_percent','Укажите процент от 0 до 100.');
    $amount=$values['C']??'';
    $formatted=is_string($amount) ? vitrine_compact_input($amount,'money') : null;
    if ($formatted===null || $formatted==='' || !vitrine_compact_integer_in_range($formatted,[0,9999999])) {
        throw new ApiError('invalid_margin_minimum','Укажите минимальную сумму от 0 до 9 999 999 ₽ целым числом.');
    }
    return ['basis'=>$basis,'rubles'=>(int)str_replace(' ','',$formatted)];
}

function workspace_margin_rule_percent_label(int $basis): string {
    return rtrim(rtrim(number_format($basis/100,2,',',''),'0'),',');
}

function workspace_margin_rule_values(string $date,array $profile): array {
    return ['A'=>workspace_margin_rule_date_label($date),
        'B'=>isset($profile['basis']) ? workspace_margin_rule_percent_label($profile['basis']) : '',
        'C'=>isset($profile['rubles']) ? vitrine_compact_input((string)$profile['rubles'],'money') : ''];
}

function workspace_margin_rule_profiles(int $catalogId): array {
    $details=wquery("SELECT e.id,m.value FROM catalog_entries e
        JOIN workspace_meta m ON m.`key`=CONCAT('margin_rule_',e.id) WHERE e.catalog_id=?",[$catalogId])->fetchAll(PDO::FETCH_KEY_PAIR);
    return array_map(static fn(string $value): array=>json_decode($value,true,flags:JSON_THROW_ON_ERROR),$details);
}

function workspace_margin_trip_date(mixed $value): ?string {
    if ((is_int($value) || is_float($value)) && $value>=1 && $value<=100000) {
        return (new DateTimeImmutable('1899-12-30',new DateTimeZone('UTC')))->modify('+'.(int)$value.' days')->format('Y-m-d');
    }
    return workspace_margin_calendar_date($value);
}

function workspace_margin_rule_check_expense(int $tableId,array $values,string $expense): void {
    $rules=wquery("SELECT e.name AS starts,m.value FROM catalog_bindings b
        JOIN catalogs c ON c.id=b.catalog_id AND c.kind='margin-rules'
        JOIN catalog_entries e ON e.catalog_id=c.id AND e.active=TRUE
        JOIN workspace_meta m ON m.`key`=CONCAT('margin_rule_',e.id)
        WHERE b.table_id=? AND b.field_key='' ORDER BY e.normalized DESC",[$tableId])->fetchAll();
    if (!$rules) return;
    $date=workspace_margin_trip_date($values['F']??null);
    if ($date===null) throw new ApiError('margin_rule_missing_date','Укажите одну дату рейса в F, чтобы проверить минимальную маржу.');
    foreach ($rules as $rule) {
        if ($rule['starts']>$date) continue;
        $profile=json_decode($rule['value'],true,flags:JSON_THROW_ON_ERROR);
        $income=workspace_trip_amount_minor($values['X']??null);
        if ($income===null) throw new ApiError('margin_rule_missing_income','Укажите доход в X, чтобы проверить минимальную маржу.');
        $minimum=max($profile['rubles']*100,intdiv($income*$profile['basis']+9999,10000));
        $max=$income-$minimum;
        $cost=workspace_trip_amount_minor($expense);
        if ($cost!==null && $cost<=$max) return;
        $formatted=number_format($minimum/100,$minimum%100 ? 2 : 0,',',' ');
        $percent=workspace_margin_rule_percent_label($profile['basis']);
        $threshold=number_format($profile['rubles'],0,',',' ');
        $ruleDate=workspace_margin_rule_date_label($rule['starts']);
        $reason='По правилу от '.$ruleDate.' минимальная маржа — '.$percent.'%, но не менее '.$threshold.' ₽. Для этого рейса нужно сохранить '.$formatted.' ₽ маржи.';
        if ($max<0) {
            throw new ApiError('margin_rule_rejected',$reason.' Доход ниже минимума.');
        }
        throw new ApiError('margin_rule_rejected',$reason.' Максимальный расход — '.number_format(intdiv($max,100),0,',',' ').' ₽.');
    }
}
