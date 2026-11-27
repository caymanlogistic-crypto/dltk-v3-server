<?php
declare(strict_types=1);
require_once __DIR__.'/VitrineClipboard.php';

function workspace_container_type_profile(string $name,array $stored=[]): array {
    $palette=array_column(workspace_palette(),'fill','name');
    $threshold=match ($name) {'20'=>20000,'40'=>24000,default=>null};
    return $stored+['typeColor'=>$name==='20' ? $palette['Бирюзовый'] : null,
        'threshold'=>$threshold,'warningColor'=>$threshold===null ? null : $palette['Красный']];
}

function workspace_container_type_rules(int $catalogId): array {
    $entries=wquery("SELECT e.id,e.name,m.value FROM catalog_entries e
        LEFT JOIN workspace_meta m ON m.`key`=CONCAT('container_type_rule_',e.id)
        WHERE e.catalog_id=?",[$catalogId]);
    $rules=[];
    foreach ($entries as $entry) {
        $stored=json_decode($entry['value']??'{}',true);
        $rules[(int)$entry['id']]=workspace_container_type_profile($entry['name'],is_array($stored) ? $stored : []);
    }
    return $rules;
}

function workspace_container_type_rule_values(array $rule): array {
    $names=array_column(workspace_palette(),'name','fill');
    return ['B'=>$names[$rule['typeColor']??'']??'',
        'C'=>$rule['threshold']===null ? '' : vitrine_compact_display((string)$rule['threshold'],'weight'),
        'D'=>$names[$rule['warningColor']??'']??''];
}

function workspace_container_type_input(array $values,array $rule): array {
    foreach (['B'=>'typeColor','D'=>'warningColor'] as $column=>$key) {
        if (array_key_exists($column,$values)) {
            $rule[$key]=$values[$column]==='' ? null : workspace_directory_color($values[$column]);
        }
    }
    if (array_key_exists('C',$values)) {
        $value=vitrine_compact_input($values['C'],'weight');
        if ($value===null || ($value!=='' && !vitrine_compact_integer_in_range($value,vitrine_compact_integer_bounds('weight')))) {
            throw new ApiError('invalid_weight_threshold','Укажите порог от 1 000 до 50 000 кг.');
        }
        $rule['threshold']=$value==='' ? null : (int)str_replace(' ','',$value);
    }
    return workspace_container_type_validate_rule($rule);
}

function workspace_container_type_validate_rule(mixed $rule): array {
    if (!is_array($rule) || !array_key_exists('typeColor',$rule) || !array_key_exists('warningColor',$rule) ||
        !array_key_exists('threshold',$rule)) {
        throw new ApiError('invalid_container_rule','Проверьте цвета и порог веса.');
    }
    $colors=array_column(workspace_palette(),'fill');
    foreach (['typeColor','warningColor'] as $field) {
        if ($rule[$field]!==null && !in_array($rule[$field],$colors,true)) {
            throw new ApiError('invalid_color','Выберите цвет из системной палитры.');
        }
    }
    $threshold=$rule['threshold'];
    if ($threshold!==null && (!is_int($threshold) || $threshold<1000 || $threshold>50000)) {
        throw new ApiError('invalid_weight_threshold','Укажите порог от 1 000 до 50 000 кг.');
    }
    return array_intersect_key($rule,['typeColor'=>true,'threshold'=>true,'warningColor'=>true]);
}
