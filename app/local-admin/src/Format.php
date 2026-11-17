<?php
declare(strict_types=1);
/** Display archived formatting only. No source service, workbook, or formula execution. */
function display_cell(array $cell): ?string {
    if(isset($cell['formattedValue']))return (string)$cell['formattedValue'];
    $value=cell_value($cell);
    if(is_bool($value))return $value?'TRUE':'FALSE';
    if(!is_int($value)&&!is_float($value))return null;
    $type=$cell['effectiveFormat']['numberFormat']['type']??'';
    if(!in_array($type,['DATE','DATE_TIME','TIME'],true)||$value<0||$value>2958465)return null;
    $stamp=(int)round(($value-25569)*86400);
    return gmdate($type==='TIME'?'H:i':($type==='DATE_TIME'?'d.m.Y H:i':'d.m.Y'),$stamp);
}
