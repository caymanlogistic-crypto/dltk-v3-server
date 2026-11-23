<?php
declare(strict_types=1);

function workspace_edo_party_roles(): array {
    return ['G'=>'Грузоотправитель','H'=>'Заказчик','I'=>'Грузополучатель','J'=>'Наблюдатель'];
}

function workspace_edo_party_role_fill(string $value): ?string {
    return $value==='Да' ? workspace_directory_color('Зелёный') : null;
}

function workspace_edo_party_role_choices(): array {
    return [['value'=>'Да','label'=>'Да','color'=>workspace_edo_party_role_fill('Да')],'Нет'];
}

function workspace_edo_party_links(): array {
    return ['AA'=>['G','AB'],'AC'=>['H','AD'],'AE'=>['I','AF'],'AG'=>['J',null]];
}

function workspace_edo_party_profile(array $input): array {
    $profile=[];
    foreach (['B'=>['ИНН','[0-9]{10}|[0-9]{12}'],'C'=>['КПП','[0-9]{9}'],'D'=>['ОГРН','[0-9]{13}|[0-9]{15}']] as $column=>[$label,$sizes]) {
        $value=is_string($input[$column]??'') ? trim($input[$column]??'') : '?';
        if ($value!=='' && !preg_match('/^(?:'.$sizes.')$/D',$value)) {
            throw new ApiError('invalid_edo_party','Проверьте поле «'.$label.'».');
        }
        $profile[$column]=$value;
    }
    $profile['F']=workspace_carrier_edo_id($input['F']??'');
    foreach (workspace_edo_party_roles() as $column=>$label) {
        $value=is_string($input[$column]??'') ? mb_strtolower(trim($input[$column]??'')) : '?';
        if (!in_array($value,['','да','нет'],true)) throw new ApiError('invalid_edo_party','Для роли «'.$label.'» выберите «Да» или «Нет».');
        $profile[$column]=$value==='да'?'Да':'Нет';
    }
    return $profile;
}

function workspace_edo_party_key(string $name,array $profile): string {
    return $profile['B']!=='' ? 'tax:'.$profile['B'].':'.$profile['C'] : 'name:'.vitrine_catalog_key($name);
}

function workspace_edo_party_values(string $name,array $profile): array {
    $profile=workspace_edo_party_profile($profile);
    return ['A'=>$name,'B'=>$profile['B'],'C'=>$profile['C'],'D'=>$profile['D'],
        'E'=>workspace_carrier_edo_operator($profile['F'])]+$profile;
}

function workspace_edo_party_label(string $name,array $profile): string {
    if (empty($profile['B'])) return $name;
    return $name.' · ИНН '.$profile['B'].(empty($profile['C'])?'':' / КПП '.$profile['C']);
}

function workspace_edo_party_profiles(int $catalogId): array {
    static $cache=[];
    if (isset($cache[$catalogId])) return $cache[$catalogId];
    $profiles=[];
    foreach (wquery("SELECT e.id,m.value FROM catalog_entries e
        JOIN workspace_meta m ON m.`key`=CONCAT('edo_party_',e.id) WHERE e.catalog_id=?",[$catalogId])->fetchAll() as $row) {
        $profiles[(int)$row['id']]=json_decode($row['value'],true,flags:JSON_THROW_ON_ERROR);
    }
    return $cache[$catalogId]=$profiles;
}

function workspace_edo_party_assert_roles(array $before,array $after,int $id): void {
    foreach (workspace_edo_party_links() as $field=>[$role]) {
        if (($before[$role]??'Нет')!=='Да' || $after[$role]==='Да') continue;
        $count=(int)wquery('SELECT COUNT(*) FROM record_references WHERE entry_id=? AND field_key=?',[$id,$field])->fetchColumn();
        if ($count) throw new ApiError('entry_in_use','Роль «'.workspace_edo_party_roles()[$role].'» используется в рейсах: '.$count.'.',409);
    }
}
