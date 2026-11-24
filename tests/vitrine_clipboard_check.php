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
foreach (['status','text','number','date','datetime','time','slot','boolean'] as $kind) verify(vitrine_clipboard_accepts('', $kind), 'Empty is allowed for '.$kind);
foreach (['800'=>'08:00','0800'=>'08:00','930'=>'09:30','2359'=>'23:59','0000'=>'00:00','8:00'=>'08:00','08:00:30'=>'08:00:30',''=>''] as $input=>$expected) verify(vitrine_time_input((string)$input)===$expected,'Time entry normalizes '.(string)$input);
foreach (['2400','1260','999','08','8','abcd'] as $input) verify(vitrine_time_input($input)===null,'Invalid time entry rejected '.$input);
foreach ([
    'Слот?'=>'Слот?',
    ' слот ? '=>'Слот?',
    '0012251026'=>'с 00 до 12 - 25.10.26',
    'с 00 до 12 - 25.10.26'=>'с 00 до 12 - 25.10.26',
    'с 00 до 12 - 25.10.2026'=>'с 00 до 12 - 25.10.26',
    '0823290228'=>'с 08 до 23 - 29.02.28',
    '0012290200'=>'с 00 до 12 - 29.02.00',
    ''=>'',
] as $input=>$expected) {
    verify(vitrine_slot_input((string)$input)===$expected,'Slot normalizes '.(string)$input);
    verify(vitrine_clipboard_accepts((string)$input,'slot'),'Slot accepts '.(string)$input);
}
foreach (['001225102','001225102026','2412251026','0024251026','0012310426','0012290227','слот? 12'] as $input) {
    verify(vitrine_slot_input((string)$input)===null,'Invalid slot rejected '.$input);
    verify(!vitrine_clipboard_accepts((string)$input,'slot'),'Invalid slot paste rejected '.$input);
}
$moscowMidnight = new DateTimeImmutable('2026-11-24T21:00:00Z');
verify(!vitrine_slot_date_in_window('с 01 до 02 - 24.11.26',$moscowMidnight),'Slot yesterday is rejected at Moscow midnight');
verify(vitrine_slot_date_in_window('с 01 до 02 - 25.11.26',$moscowMidnight),'Slot today is allowed');
verify(vitrine_slot_date_in_window('с 01 до 02 - 25.12.26',$moscowMidnight),'Slot exactly 30 days ahead is allowed');
verify(!vitrine_slot_date_in_window('с 01 до 02 - 26.12.26',$moscowMidnight),'Slot 31 days ahead is rejected');
verify(!vitrine_slot_date_in_window('с 02 до 06 - 12.12.62',$moscowMidnight),'Slot in 2062 is rejected');
verify(vitrine_slot_date_in_window('Слот?',$moscowMidnight),'Slot request has no date');
verify(vitrine_slot_date_in_window('',$moscowMidnight),'Clearing a slot has no date');
foreach (['251026'=>'25.10.26','25.10.26'=>'25.10.26','25.10.2026'=>'25.10.26',
    '2026-10-25'=>'25.10.26','290200'=>'29.02.00',''=>''] as $input=>$expected) {
    verify(vitrine_compact_input((string)$input,'date')===$expected,'Compact date normalizes '.$input);
}
foreach (['310426','290225','2510','25.10.1999','25.10.26 09:00'] as $input) {
    verify(vitrine_compact_input($input,'date')===null,'Compact date rejects '.$input);
}
foreach (['0830'=>'08:30','800'=>'08:00','8:00'=>'08:00','23:59'=>'23:59',''=>''] as $input=>$expected) {
    verify(vitrine_compact_input((string)$input,'time')===$expected,'Compact time normalizes '.$input);
}
foreach (['24:00','12:60','08','08:00:15'] as $input) {
    verify(vitrine_compact_input($input,'time')===null,'Compact time rejects '.$input);
}
foreach (['BICU6658782','bicu 665878-2'] as $input) {
    verify(vitrine_container_input($input)==='BICU6658782','ISO example normalizes '.$input);
}
foreach (['BICU6658783','BICJ6658782','БICU6658782','BICU665878','BICU66587822','---'] as $input) {
    verify(vitrine_container_input($input)===null,'Invalid freight container rejected '.$input);
}
foreach (['25990'=>'25 990','25 990'=>'25 990',"25\u{202F}990"=>'25 990','0000'=>'0',''=>''] as $input=>$expected) {
    verify(vitrine_compact_input((string)$input,'weight')===$expected,'Weight formats '.$input);
}
foreach (['25.5','25,5','25кг','-25','+25','1 00'] as $input) {
    verify(vitrine_compact_input($input,'weight')===null,'Non-integer weight rejected '.$input);
}
foreach ([''=>'', '0'=>'0','999'=>'999','1 000'=>'1 000','50 000'=>'50 000','50 001'=>'50 001'] as $input=>$expected) {
    $formatted = vitrine_compact_input((string)$input,'weight');
    verify($formatted===$expected,'Weights stay formatted for existing rows '.$input);
    verify(vitrine_weight_in_range($formatted)===in_array($expected,['','1 000','50 000'],true),'Weight range boundary '.$input);
}
echo 'PASS '.$checks." clipboard format checks\n";
