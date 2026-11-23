<?php
declare(strict_types=1);

const DIRECTORY_XLSX_MAX_BYTES = 1_900_000;
const DIRECTORY_XLSX_MAX_ROWS = 5000;

function directory_xlsx_error(string $message = 'Выберите действительную книгу Excel в формате .xlsx.'): never {
    throw new ApiError('invalid_xlsx',$message);
}

function directory_xlsx_xml(ZipArchive $zip,string $path): DOMDocument {
    $part = $zip->statName($path);
    if (!$part || $part['size'] > 16_000_000) directory_xlsx_error();
    $xml = $zip->getFromName($path);
    $doc = new DOMDocument();
    if (!$xml || !@$doc->loadXML($xml,LIBXML_NONET) || $doc->doctype) directory_xlsx_error();
    return $doc;
}

function directory_xlsx_text(DOMNode $node): string {
    $xpath = new DOMXPath($node->ownerDocument);
    $text = '';
    foreach ($xpath->query('./*[local-name()="t"] | ./*[local-name()="r"]/*[local-name()="t"]',$node) as $part) {
        $text .= $part->textContent;
    }
    return preg_replace_callback('/_x([0-9a-fA-F]{4})_/',static function(array $match): string {
        $code = hexdec($match[1]);
        return $code >= 0xD800 && $code <= 0xDFFF ? $match[0] : mb_chr($code,'UTF-8');
    },$text);
}

function directory_xlsx_cell(DOMElement $cell,array $shared,int $row): string {
    if ($cell->getElementsByTagNameNS('*','f')->length) {
        directory_xlsx_error('Строка '.$row.'. Замените формулы готовыми значениями.');
    }
    $type = $cell->getAttribute('t');
    $value = $cell->getElementsByTagNameNS('*','v')->item(0)?->textContent ?? '';
    return match ($type) {
        's' => ctype_digit($value) && isset($shared[(int)$value])
            ? $shared[(int)$value] : directory_xlsx_error(),
        'inlineStr' => ($inline=$cell->getElementsByTagNameNS('*','is')->item(0)) ? directory_xlsx_text($inline) : '',
        '', 'n', 'str', 'd' => $value,
        default => directory_xlsx_error('Строка '.$row.'. Проверьте тип значения в ячейке '.$cell->getAttribute('r').'.'),
    };
}

function directory_xlsx_read(array $file): array {
    if (($file['error']??null)!==UPLOAD_ERR_OK || !is_string($file['name']??null) ||
        !preg_match('/\.xlsx$/iD',$file['name']) || ($file['size']??0)>DIRECTORY_XLSX_MAX_BYTES) {
        directory_xlsx_error('Выберите файл .xlsx размером до 1,9 МБ.');
    }
    if (!class_exists(ZipArchive::class) || !class_exists(DOMDocument::class)) {
        throw new ApiError('xlsx_unavailable','Чтение Excel недоступно в этой установке.',503);
    }
    $zip = new ZipArchive();
    if ($zip->open($file['tmp_name'],ZipArchive::RDONLY)!==true) directory_xlsx_error();
    try {
        $types = directory_xlsx_xml($zip,'[Content_Types].xml');
        $bookType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';
        $validType = false;
        foreach ($types->getElementsByTagNameNS('*','Override') as $override) {
            if ($override->getAttribute('PartName')==='/xl/workbook.xml') {
                $validType = $override->getAttribute('ContentType')===$bookType;
            }
        }
        if (!$validType) directory_xlsx_error();
        $book = directory_xlsx_xml($zip,'xl/workbook.xml');
        $sheets = $book->getElementsByTagNameNS('*','sheet');
        if ($sheets->length!==1) directory_xlsx_error('В книге должен быть один лист со справочником.');
        $relationId = $sheets->item(0)->getAttributeNS(
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships','id');
        $relations = directory_xlsx_xml($zip,'xl/_rels/workbook.xml.rels');
        $sheetPath = '';
        foreach ($relations->getElementsByTagNameNS('*','Relationship') as $relation) {
            if ($relation->getAttribute('Id')===$relationId &&
                str_ends_with($relation->getAttribute('Type'),'/worksheet') &&
                $relation->getAttribute('TargetMode')!=='External') {
                $sheetPath = preg_replace('~^(?:/xl/)?~','xl/',$relation->getAttribute('Target'),1);
            }
        }
        if (!preg_match('~^xl/worksheets/[a-zA-Z0-9_-]+\.xml$~D',$sheetPath)) directory_xlsx_error();
        $shared = [];
        if ($zip->locateName('xl/sharedStrings.xml')!==false) {
            foreach (directory_xlsx_xml($zip,'xl/sharedStrings.xml')->getElementsByTagNameNS('*','si') as $item) {
                $shared[] = directory_xlsx_text($item);
            }
        }
        $result = [];
        foreach (directory_xlsx_xml($zip,$sheetPath)->getElementsByTagNameNS('*','row') as $row) {
            if (count($result)>DIRECTORY_XLSX_MAX_ROWS) directory_xlsx_error('В файле допускается до 5000 записей.');
            $number = (int)$row->getAttribute('r');
            if ($number<=0 || isset($result[$number])) directory_xlsx_error();
            $cells = [];
            $next = 1;
            foreach ($row->getElementsByTagNameNS('*','c') as $cell) {
                $reference = $cell->getAttribute('r');
                if ($reference!=='' && (!preg_match('/^([A-Z]{1,3})([1-9][0-9]*)$/D',$reference,$address) ||
                    (int)$address[2]!==$number)) directory_xlsx_error();
                $column = $reference==='' ? $next : column_number($address[1]);
                if ($column>64 || isset($cells[$column])) directory_xlsx_error('Проверьте столбцы в строке '.$number.'.');
                $cells[$column] = directory_xlsx_cell($cell,$shared,$number);
                $next = $column+1;
            }
            if (array_filter($cells,static fn(string $value): bool=>trim($value)!=='')) $result[$number]=$cells;
        }
        ksort($result);
        return $result;
    } finally {
        $zip->close();
    }
}
