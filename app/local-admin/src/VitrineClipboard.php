<?php
declare(strict_types=1);

/** Clipboard format compatibility only; this does not define business field types. */
function vitrine_clipboard_kind(array $cell, string $letter): string {
    if ($letter === 'A') return 'status';
    $value = $cell['effectiveValue'] ?? $cell['userEnteredValue'] ?? [];
    if (array_key_exists('stringValue', $value) || array_key_exists('errorValue', $value) || array_key_exists('formulaValue', $value)) return 'text';
    if (array_key_exists('boolValue', $value)) return 'boolean';
    $format = $cell['effectiveFormat']['numberFormat']['type'] ?? $cell['userEnteredFormat']['numberFormat']['type'] ?? '';
    $numeric = array_key_exists('numberValue', $value);
    if ($numeric || $value === []) {
        if ($format === 'DATE') return 'date';
        if ($format === 'DATE_TIME') return 'datetime';
        if ($format === 'TIME') return 'time';
        if ($numeric || in_array($format, ['NUMBER', 'PERCENT', 'CURRENCY', 'SCIENTIFIC'], true)) return 'number';
    }
    return 'text';
}

function vitrine_clipboard_status_key(string $value): string {
    return mb_strtolower(trim((string)preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $value)), 'UTF-8');
}

function vitrine_clipboard_date(string $value): bool {
    if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/D', $value, $m)) return checkdate((int)$m[2], (int)$m[1], (int)$m[3]);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m)) return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
    return false;
}

function vitrine_clipboard_time(string $value): bool {
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/D', $value, $m)) return false;
    return (int)$m[1] < 24 && (int)$m[2] < 60 && (!isset($m[3]) || (int)$m[3] < 60);
}

function vitrine_time_input(string $value): ?string {
    $value = trim($value);
    if ($value === '') return '';
    if (preg_match('/^\d{3,4}$/D', $value)) {
        $value = str_pad($value,4,'0',STR_PAD_LEFT);
        $value = substr($value,0,2).':'.substr($value,2,2);
    }
    if (!vitrine_clipboard_time($value)) return null;
    return str_pad($value, strlen($value) + (strpos($value,':') === 1 ? 1 : 0), '0', STR_PAD_LEFT);
}

/** Normalize the digit masks used by the date and time columns in «Вывоз». */
function vitrine_compact_input(string $value, string $kind): ?string {
    $value = trim($value);
    if ($value === '') return '';
    if ($kind === 'time') {
        $time = vitrine_time_input($value);
        return $time !== null && strlen($time) === 5 ? $time : null;
    }
    if (preg_match('/^20([0-9]{2})-([0-9]{2})-([0-9]{2})$/D',$value,$iso)) {
        $value = "$iso[3].$iso[2].$iso[1]";
    }
    if (!preg_match('/^([0-9]{2})([0-9]{2})([0-9]{2})$/D',$value,$date)
        && !preg_match('/^([0-9]{2})\.([0-9]{2})\.(?:20)?([0-9]{2})$/D',$value,$date)) return null;
    [, $day, $month, $year] = $date;
    return checkdate((int)$month,(int)$day,2000+(int)$year) ? "$day.$month.$year" : null;
}

/** Normalize a slot request, ten digits, or the current/legacy display format. */
function vitrine_slot_input(string $value): ?string {
    $value = trim($value);
    if ($value === '') return '';
    if (preg_match('/^слот[\s\x{00A0}\x{202F}]*\?$/iuD',$value)) return 'Слот?';
    if (preg_match('/^с ([0-9]{2}) до ([0-9]{2}) - ([0-9]{2})\.([0-9]{2})\.(?:20)?([0-9]{2})$/uD',$value,$parts)) {
        $value = implode('',array_slice($parts,1));
    }
    if (!preg_match('/^([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})([0-9]{2})$/D',$value,$parts)) return null;
    [, $from, $to, $day, $month, $year] = $parts;
    if ((int)$from>23 || (int)$to>23 || !checkdate((int)$month,(int)$day,2000+(int)$year)) return null;
    return "с $from до $to - $day.$month.$year";
}

/** Validate pasted text without coercing it, evaluating formulas, or rewriting the source. */
function vitrine_clipboard_accepts(string $value, string $kind, array $choices = []): bool {
    if (!mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > 20000) return false;
    // An empty clipboard value clears the cell; missing data is not a format error.
    if ($value === '') return true;
    if ($choices !== []) {
        $found = false;
        foreach ($choices as $choice) {
            if (!is_scalar($choice)) continue;
            if ($kind === 'status' ? vitrine_clipboard_status_key($value) === vitrine_clipboard_status_key((string)$choice) : $value === (string)$choice) {
                $found = true;
                break;
            }
        }
        if (!$found) return false;
    }
    $trimmed = trim($value);
    return match ($kind) {
        'text' => true,
        'status' => $choices !== [],
        'boolean' => in_array(strtoupper($trimmed), ['TRUE', 'FALSE'], true),
        'date' => vitrine_clipboard_date($trimmed),
        'time' => vitrine_clipboard_time($trimmed),
        'slot' => vitrine_slot_input($value) !== null,
        'datetime' => preg_match('/^(.+?)[ T](\d{1,2}:\d{2}(?::\d{2})?)$/D', $trimmed, $m) === 1 && vitrine_clipboard_date($m[1]) && vitrine_clipboard_time($m[2]),
        // Displayed numbers may carry locale grouping and a percent/currency suffix.
        // Currency/percent are formats of a number, not separately inferred business types.
        'number' => preg_match('/^[+-]?(?:\d+|\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+)(?:[.,]\d+)?(?:[eE][+-]?\d+)?(?:[ \x{00A0}\x{202F}]*(?:[%₽€$]|руб\.?))?$/uD', $trimmed) === 1,
        default => false,
    };
}
