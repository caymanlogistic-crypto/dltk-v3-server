<?php
declare(strict_types=1);

function workspace_trip_margin_enabled(array $table): bool {
    if ($table['slug']!=='vyvoz') return false;
    $labels=array_column($table['columns'],'label','letter');
    foreach (['X'=>'доход','Y'=>'расход','Z'=>'маржа'] as $column=>$label) {
        if (mb_strtolower(trim($labels[$column]??''))!==$label) return false;
    }
    return true;
}

// Use integer hundredths so archived fractional amounts subtract exactly.
function workspace_trip_amount_minor(mixed $value): ?int {
    if (is_float($value)) $value=number_format($value,2,'.','');
    if (!is_string($value) && !is_int($value)) return null;
    if (!preg_match('/^([+-]?)((?:[0-9]+|[0-9]{1,3}(?:[ \x{00A0}\x{202F}][0-9]{3})+))(?:[.,]([0-9]{1,2}))?$/uD',
        trim((string)$value),$parts)) return null;
    $whole=str_replace([' ',"\u{00A0}","\u{202F}"],'',$parts[2]);
    if (strlen($whole)>12) return null;
    $minor=(int)$whole*100+(int)str_pad($parts[3]??'',2,'0');
    return $parts[1]==='-' ? -$minor : $minor;
}

function workspace_trip_margin(array $values): string {
    $income=workspace_trip_amount_minor($values['X']??null);
    $cost=workspace_trip_amount_minor($values['Y']??null);
    if ($income===null || $cost===null) return '';
    return workspace_trip_amount_label($income-$cost);
}

function workspace_trip_amount_label(int $minor): string {
    $absolute=abs($minor);
    $whole=vitrine_compact_input((string)intdiv($absolute,100),'money');
    $fraction=$absolute%100;
    return ($minor<0 ? '-' : '').$whole.($fraction ? ','.str_pad((string)$fraction,2,'0',STR_PAD_LEFT) : '');
}
