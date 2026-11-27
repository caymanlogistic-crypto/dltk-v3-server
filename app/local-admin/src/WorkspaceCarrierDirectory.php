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

function workspace_carrier_logist_accounts(int $catalogId): array {
    return workspace_client_catalog_metadata('catalog_logist_accounts_'.$catalogId);
}

function workspace_carrier_logist_people(): array {
    $accounts=array_filter(workspace_employee_records(),
        static fn(array $account): bool=>!workspace_employee_is_private($account));
    $counts=array_count_values(array_map(static fn(array $account): string=>vitrine_catalog_key($account['name']),$accounts));
    $people=[];
    foreach ($accounts as $account) {
        $id=$account['id'];
        $name=$account['name'];
        $suffix=' · №'.$id;
        $label=$counts[vitrine_catalog_key($name)]===1 ? $name : mb_substr($name,0,160-mb_strlen($suffix)).$suffix;
        $eligible=$account['active'] && (bool)array_intersect(
            ['logist','directory_senior_logist'],array_column($account['roles'],'code'));
        $people[$id]=['label'=>$label,'eligible'=>$eligible];
    }
    return $people;
}

function workspace_carrier_logist_choices(): array {
    // Keep the standard select available even if no accounts can be assigned.
    return ['',...array_column(array_filter(workspace_carrier_logist_people(),
        static fn(array $person): bool=>$person['eligible']),'label')];
}

function workspace_carrier_logist_values(int $catalogId): array {
    $links=workspace_carrier_logist_accounts($catalogId);
    if (!$links) return [];
    $people=workspace_carrier_logist_people();
    return array_map(static fn($id): string=>$people[$id]['label']??'',$links);
}

function workspace_carrier_logist_input(int $catalogId,int $carrierId,string $label): array {
    if ($label==='') return ['',0];
    $accountId=(int)(workspace_carrier_logist_accounts($catalogId)[$carrierId]??0);
    $key=vitrine_catalog_key($label);
    $matches=[];
    foreach (workspace_carrier_logist_people() as $id=>$person) {
        if (($id===$accountId || $person['eligible']) && vitrine_catalog_key($person['label'])===$key) {
            $matches[$id]=$person['label'];
        }
    }
    if (count($matches)===1) return [reset($matches),array_key_first($matches)];
    throw new ApiError('invalid_logist','Выберите действующего логиста из учётных записей.');
}

function workspace_carrier_directory_values(int $catalogId): array {
    $logists=workspace_carrier_logist_values($catalogId);
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
