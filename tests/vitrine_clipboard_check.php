<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/local-admin/src/VitrineClipboard.php';
$checks = 0;
function verify(bool $condition, string $name): void {
    global $checks;
    if (!$condition) throw new RuntimeException($name);
    $checks++;
}
function sample(mixed $value, string $format = ''): array {
    $key = is_bool($value) ? 'boolValue' : (is_int($value) || is_float($value) ? 'numberValue' : 'stringValue');
    return ['effectiveValue'=>[$key=>$value], 'effectiveFormat'=>['numberFormat'=>['type'=>$format]]];
}

verify(vitrine_clipboard_kind(sample('79161234567', 'DATE'), 'Q') === 'text', 'A phone string with DATE decoration is still text');
verify(vitrine_clipboard_kind(sample('вывоз до 23:59 02.11', 'NUMBER'), 'D') === 'text', 'Mixed textual contents remain text');
verify(vitrine_clipboard_kind(sample(10, 'DATE'), 'F') === 'date', 'Numeric date serial uses its actual date format');
verify(vitrine_clipboard_kind(sample(10, 'TEXT'), 'J') === 'number', 'A numeric value remains numeric');
verify(vitrine_clipboard_kind(sample(false), 'T') === 'boolean', 'FALSE is not empty');
verify(vitrine_clipboard_kind(['effectiveFormat'=>['numberFormat'=>['type'=>'DATE']]], 'F') === 'date', 'Empty explicitly formatted date');
verify(vitrine_clipboard_kind(['effectiveFormat'=>['numberFormat'=>['type'=>'TIME']]], 'G') === 'time', 'Empty explicitly formatted time');
verify(vitrine_clipboard_kind([], 'B') === 'text', 'Unknown empty cell uses text');
verify(vitrine_clipboard_kind(['effectiveValue'=>['errorValue'=>['type'=>'N_A']]], 'Y') === 'text', 'Formula error is not a numeric format contract');
verify(vitrine_clipboard_kind(sample('anything'), 'A') === 'status', 'A belongs to the explicit local status catalog');

foreach (['29.02.2024', '2024-02-29', '17.11.2026'] as $value) verify(vitrine_clipboard_accepts($value, 'date'), 'Valid date '.$value);
foreach (['29.02.2025', '31.04.2026', '2026-13-01', '17.11', '45200'] as $value) verify(!vitrine_clipboard_accepts($value, 'date'), 'Invalid date '.$value);
verify(vitrine_clipboard_accepts('2026-11-17T09:05:59', 'datetime'), 'ISO datetime');
verify(vitrine_clipboard_accepts('17.11.2026 9:05', 'datetime'), 'Local datetime');
verify(!vitrine_clipboard_accepts('29.02.2025 09:00', 'datetime'), 'Datetime validates calendar date');
verify(!vitrine_clipboard_accepts('17.11.2026 24:00', 'datetime'), 'Datetime validates clock');
verify(vitrine_clipboard_accepts('0:00', 'time'), 'Midnight');
verify(!vitrine_clipboard_accepts('12:60', 'time'), 'Invalid minute');
foreach (['0', '-1234,50', '1 234.50', "12\u{00A0}345", '82 500 ₽', '12,5%', '1.2e-3'] as $value) verify(vitrine_clipboard_accepts($value, 'number'), 'Valid numeric display '.$value);
foreach (['1 23', '12,34.5', '=1+2', 'текст', '17.11.2026'] as $value) verify(!vitrine_clipboard_accepts($value, 'number'), 'Invalid numeric display '.$value);
verify(vitrine_clipboard_accepts('false', 'boolean'), 'Boolean names');
verify(!vitrine_clipboard_accepts('0', 'boolean'), 'No implicit boolean conversion');
verify(vitrine_clipboard_accepts('  В   ПУТИ ', 'status', ['в пути', 'сдан']), 'Status normalized lookup');
verify(!vitrine_clipboard_accepts('сдал', 'status', ['сдан']), 'No guessed status aliases');
verify(!vitrine_clipboard_accepts('unknown', 'status'), 'Status needs actual catalog');
verify(vitrine_clipboard_accepts('А', 'text', ['А','Б']), 'Exact enum entry');
verify(!vitrine_clipboard_accepts('а', 'text', ['А','Б']), 'Enum does not silently rewrite case');
verify(vitrine_clipboard_accepts("<text>\n79161234567", 'text'), 'Plain text is retained as text');
verify(!vitrine_clipboard_accepts(str_repeat('я',20001), 'text'), 'Length limit');
foreach (['status','text','number','date','datetime','time','boolean'] as $kind) verify(vitrine_clipboard_accepts('', $kind), 'Empty is allowed for '.$kind);
foreach (['800'=>'08:00','0800'=>'08:00','930'=>'09:30','2359'=>'23:59','0000'=>'00:00','8:00'=>'08:00','08:00:30'=>'08:00:30',''=>''] as $input=>$expected) verify(vitrine_time_input((string)$input)===$expected,'Time entry normalizes '.(string)$input);
foreach (['2400','1260','999','08','8','abcd'] as $input) verify(vitrine_time_input($input)===null,'Invalid time entry rejected '.$input);
echo 'PASS '.$checks." clipboard format checks\n";
