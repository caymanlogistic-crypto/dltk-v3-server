<?php
declare(strict_types=1);

function workspace_fleet_phone_number(string $value): ?string {
    $compact=preg_replace('/[\s().\-–—]+/u','',$value);
    if (preg_match('/^(?:\+?375|00375|810375)([1-4][0-9])([0-9]{3})([0-9]{2})([0-9]{2})$/D',$compact,$phone)
        || preg_match('/^80(25|29|33|44)([0-9]{3})([0-9]{2})([0-9]{2})$/D',$compact,$phone)) {
        return "+375 ($phone[1]) $phone[2] $phone[3] $phone[4]";
    }
    if (preg_match('/^(?:\+?7|8)?([3489][0-9]{2})([0-9]{3})([0-9]{2})([0-9]{2})$/D',$compact,$phone)) {
        return "+7 ($phone[1]) $phone[2] $phone[3] $phone[4]";
    }
    return null;
}

function workspace_phone_digits_display(string $value): ?string {
    if (!preg_match('/^[0-9]{1,17}$/D',$value)) return null;
    $explicitInternational=str_starts_with($value,'00');
    if (!$explicitInternational) {
        $recognized=workspace_fleet_phone_number($value);
        if ($recognized!==null) return $recognized;
    }
    $number=$explicitInternational ? substr($value,2) : $value;
    if (!preg_match('/^[1-9][0-9]{6,14}$/D',$number)) return null;
    if (str_starts_with($number,'7')) {
        if (!preg_match('/^7([0-9]{3})([0-9]{3})([0-9]{2})([0-9]{2})$/D',$number,$parts)) return null;
        return "+7 ($parts[1]) $parts[2] $parts[3] $parts[4]";
    }
    if (str_starts_with($number,'375')) return workspace_fleet_phone_number('+'.$number);
    return '+'.$number;
}

function workspace_phone_paste_digits(string $value): ?string {
    $value=preg_replace('/^[ \t\r\n\x{00A0}\x{2009}\x{202F}]+|[ \t\r\n\x{00A0}\x{2009}\x{202F}]+$/u','',$value);
    if ($value==='') return '';
    if ($value===null || !preg_match('/^\+?[0-9(). \x{00A0}\x{2009}\x{202F}\-–—]+$/uD',$value)) return null;
    $digits=preg_replace('/[^0-9]/','',$value);
    if ($digits==='') return null;
    $knownCode=preg_match('/^(?:7[0-9]{10}|375[0-9]{9})$/D',$digits);
    return str_starts_with($value,'+') && !$knownCode ? '00'.$digits : $digits;
}

function workspace_phone_display(string $value): string {
    $digits=workspace_phone_paste_digits($value);
    return ($digits===null ? null : workspace_phone_digits_display($digits)) ?? $value;
}

function workspace_phone_storage(mixed $value, ?string $previous=null): string {
    if (!is_string($value) || mb_strlen($value)>100) {
        throw new ApiError('phone_format','Укажите один полный номер телефона с кодом страны.');
    }
    if ($previous!==null) {
        $shown=workspace_phone_display($previous);
        $singleLine=str_replace(["\r","\n"],'',$shown);
        if ($value===$previous || $value===$singleLine || ($value!=='' && $value===trim($shown))) return $previous;
    }
    $digits=workspace_phone_paste_digits($value);
    if ($digits==='') return '';
    $shown=$digits===null ? null : workspace_phone_digits_display($digits);
    if ($shown===null) throw new ApiError('phone_format','Укажите один полный номер телефона с кодом страны.');
    return '+'.preg_replace('/[^0-9]/','',$shown);
}

function workspace_fleet_phone_list(string $value): ?array {
    $value=preg_replace('/\b(?:телефоны?\s+водителя|контактный\s+телефон|тел(?:ефон)?|моб(?:ильный)?|раб(?:очий)?|лич(?:ный)?|сот(?:овый)?|водитель|доп(?:олнительный)?\s+телефон)\b[.:\s№]*/iu',' ',trim($value));
    $parts=preg_split('/[,;\/\r\n]+|\s+(?=\+)/u',trim($value),-1,PREG_SPLIT_NO_EMPTY);
    $numbers=[];
    foreach ($parts as $part) {
        if (trim($part)==='') continue;
        $number=workspace_fleet_phone_number(trim($part));
        if ($number!==null) {
            $numbers[]=$number;
            continue;
        }
        // Some imported cells separate two complete telephone numbers with only a space.
        preg_match_all('/\s+(?=[+0-9(])/u',$part,$spaces,PREG_OFFSET_CAPTURE);
        $pairs=[];
        foreach ($spaces[0] as [$separator,$offset]) {
            $left=workspace_fleet_phone_number(substr($part,0,$offset));
            $right=workspace_fleet_phone_number(substr($part,$offset+strlen($separator)));
            if ($left!==null && $right!==null) $pairs[$left.'|'.$right]=[$left,$right];
        }
        if (count($pairs)!==1) return null;
        $numbers=array_merge($numbers,reset($pairs));
    }
    return array_values(array_unique($numbers));
}

function workspace_fleet_trip_phone_display(string $value): string {
    $numbers=workspace_fleet_phone_list($value);
    return $numbers ? implode(', ',$numbers) : workspace_phone_display($value);
}

function workspace_fleet_phone_input(string $value): string {
    $numbers=workspace_fleet_phone_list($value);
    if (count($numbers??[])===1) return $numbers[0];
    // Keep notes, extensions and unfamiliar country codes verbatim.
    return preg_replace_callback(
        '/(?<![+\d])(?:\+375|375)[\s().\-–—]*[1-4][0-9](?:[\s().\-–—]*[0-9]){7}(?![\s().\-–—]*[0-9])|(?<![+\d])\+7[\s().\-–—]*[3489](?:[\s().\-–—]*[0-9]){9}(?![\s().\-–—]*[0-9])/u',
        static fn(array $match): string=>workspace_fleet_phone_number($match[0])??$match[0],$value);
}

function workspace_fleet_phone_pair(string $primary,string $additional): array {
    $first=workspace_fleet_phone_list($primary);
    $second=workspace_fleet_phone_list($additional);
    if ($first===null || $second===null) {
        return [workspace_fleet_phone_input($primary),workspace_fleet_phone_input($additional)];
    }
    if (count($first)>1 || count($second)>1) {
        $numbers=array_values(array_unique([...$first,...$second]));
        if (count($numbers)>2) return [workspace_fleet_phone_input($primary),workspace_fleet_phone_input($additional)];
        return [$numbers[0]??'', $numbers[1]??''];
    }
    return [$first[0]??'', $second[0]??''];
}
