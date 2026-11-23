<?php
declare(strict_types=1);

function workspace_carrier_details(mixed $values): array {
    if (!is_array($values)) throw new ApiError('invalid_carrier','Проверьте реквизиты перевозчика.');
    $details=[];
    foreach (['B','C'] as $column) {
        $value=$values[$column]??'';
        if (!is_string($value) || mb_strlen($value)>160 || preg_match('/[\x00-\x1F]/u',$value)) {
            throw new ApiError('invalid_carrier','Проверьте реквизиты перевозчика. Допустимо до 160 символов в поле.');
        }
        $details[$column]=trim($value);
    }
    $details['edoId']=workspace_carrier_edo_id($values['edoId']??'');
    return $details;
}

function workspace_carrier_edo_id(mixed $value): string {
    if (!is_string($value)) throw new ApiError('invalid_edo_id','Укажите полный идентификатор участника ЭДО.');
    $id=trim($value);
    if ($id!=='' && !preg_match('/^2[A-Z0-9]{2}-?[A-Z0-9][A-Z0-9@.\-]{0,155}$/iD',$id)) {
        throw new ApiError('invalid_edo_id','Укажите полный идентификатор участника ЭДО.');
    }
    return $id;
}

function workspace_carrier_edo_operator(string $id): string {
    if ($id==='') return '';
    return workspace_edo_operator_names()[strtolower(substr($id,0,3))]??'';
}

function workspace_carrier_values(array $details,string $logist=''): array {
    return ['B'=>$details['B']??'', 'C'=>$details['edoId']??'',
        'D'=>workspace_carrier_edo_operator($details['edoId']??''), 'E'=>$logist];
}

function workspace_carrier_entry_details(int $entryId): array {
    return workspace_carrier_details(workspace_client_catalog_metadata('carrier_details_'.$entryId));
}

function workspace_carrier_directory_values(int $catalogId): array {
    $logists=workspace_client_catalog_metadata('catalog_logists_'.$catalogId);
    $values=[];
    foreach (wquery("SELECT e.id,m.value FROM catalog_entries e
        LEFT JOIN workspace_meta m ON m.`key`=CONCAT('carrier_details_',e.id)
        WHERE e.catalog_id=?",[$catalogId])->fetchAll() as $row) {
        $id=(int)$row['id'];
        $details=$row['value']===null ? [] : (json_decode($row['value'],true)??[]);
        $values[$id]=workspace_carrier_values(workspace_carrier_details($details),$logists[$id]??'');
    }
    return $values;
}
