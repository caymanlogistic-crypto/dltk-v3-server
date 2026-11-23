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
    return $details;
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
        $values[$id]=workspace_carrier_details($details)+['D'=>$logists[$id]??''];
    }
    return $values;
}
