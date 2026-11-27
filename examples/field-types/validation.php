<?php
declare(strict_types=1);

require_once dirname(__DIR__,2).'/app/local-admin/src/Vitrine.php';

function preview_error(string $message,string $code='preview_invalid'): never {
    json_response(['error'=>['code'=>$code,'message'=>$message]],422);
}

function preview_choice(array $choices,string $input,bool $required): array {
    if ($input==='' && !$required) return ['value'=>''];
    foreach ($choices as $choice) {
        $item=is_array($choice) ? $choice : ['value'=>$choice];
        if ($item['value']===$input) return $item;
    }
    preview_error('Выберите значение из списка.');
}

function preview_document_date(string $input,string $type): string {
    $fields=[
        'passport_number'=>'','passport_issuer'=>'','license_number'=>'',
        'birth_date'=>null,'passport_issued'=>null,'license_issued'=>null,'license_expires'=>null,
    ];
    $seed='2021-08-11';
    switch ($type) {
        case 'birth-date': $column='P'; break;
        case 'license-issued':
            $column='L';
            $fields['license_expires']='2031-08-11';
            break;
        default:
            $column='M';
            $fields['license_issued']=$seed;
    }
    $field=workspace_fleet_document_fields()[$column];
    $values=workspace_fleet_validate_documents($fields,[$column=>$input]);
    return $values[$field]===null ? '' : workspace_margin_rule_date_label($values[$field]);
}

function preview_passport(string $value): string {
    $formatted=workspace_fleet_passport_format(workspace_unload_text($value,50));
    if ($formatted!==null) return $formatted;
    preview_error('Введите 10 цифр, 9 цифр или две буквы и 7 цифр номера паспорта.');
}

function preview_value(array $sample,string $input,bool $paste,DateTimeImmutable $today): string {
    $type=$sample['type'];
    if ($type==='password') {
        if (mb_strlen($input)<8 || strlen($input)>72 || str_contains($input,"\0")) {
            preview_error('Пароль должен содержать от 8 символов и занимать до 72 байт.');
        }
        return $sample['value'];
    }
    if ($input==='' && !in_array($type,['percent','minimum','rule-date','login','edo-code','name','plate','surname','given-name','unload-address'],true)) return '';
    if ($type==='phone') return workspace_phone_display(workspace_phone_storage($input));
    if ($type==='slot') {
        $slot=vitrine_slot_input($input);
        if ($slot===null) preview_error('Введите 10 цифр: два часа и календарную дату.');
        if (!vitrine_slot_date_in_window($slot,$today)) {
            preview_error('Укажите дату слота от сегодняшней до 30 календарных дней вперёд включительно.');
        }
        return $slot;
    }
    if ($type==='date') {
        if ($paste && !preg_match('/^[0-9]{6}$/D',$input)) preview_error('Введите шесть цифр в формате ДДММГГ.');
        $date=vitrine_compact_input($input,'date');
        if ($date===null) preview_error('Введите действительную дату в формате ДДММГГ.');
        if (!vitrine_date_in_window($date,10,60,$today)) {
            preview_error('Дата рейса может быть от 10 дней назад до 60 дней вперёд включительно.');
        }
        return $date;
    }
    if ($type==='time' || $type==='container') {
        $value=vitrine_compact_input($input,$type);
        if ($value===null) {
            preview_error($type==='time' ? 'Введите часы 00–23 и минуты 00–59.' : 'Проверьте формат и контрольную цифру номера контейнера.');
        }
        return $value;
    }
    $numberType=in_array($type,['expense','minimum'],true) ? 'money' : $type;
    if (vitrine_compact_integer_bounds($numberType)!==null) {
        $number=vitrine_compact_input($input,$numberType);
        if ($number===null) preview_error('Введите целое число.');
        vitrine_require_compact_integer_range($number,$numberType);
        if ($type==='minimum' && $number==='') preview_error('Укажите минимальную сумму.');
        if ($type==='expense' && workspace_trip_amount_minor($number)>4950000) {
            preview_error('При учебном доходе 55 000 ₽ и пороге 10% максимальный расход — 49 500 ₽.','margin_rule_rejected');
        }
        return $number;
    }
    return match ($type) {
        'rule-date'=>vitrine_compact_input(workspace_margin_rule_date($input),'date'),
        'percent'=>workspace_margin_rule_percent_label(workspace_margin_rule_profile(['B'=>$input,'C'=>'5000'])['basis']),
        'line'=>preview_single_line($input),
        'email','login'=>workspace_employee_profile_value($type,$input),
        'name'=>workspace_employee_profile_value('name',$input),
        'carrier-inn'=>workspace_carrier_details(['B'=>$input])['B'],
        'inn'=>workspace_edo_party_profile(['B'=>$input])['B'],
        'kpp'=>workspace_edo_party_profile(['C'=>$input])['C'],
        'ogrn'=>workspace_edo_party_profile(['D'=>$input])['D'],
        'edo-id'=>workspace_carrier_edo_id($input),
        'edo-code'=>workspace_edo_operator_code($input),
        'birth-date','license-issued','license-date'=>preview_document_date($input,$type),
        'document-line'=>workspace_unload_text($input,$sample['limit']),
        'passport'=>preview_passport($input),
        'plate'=>workspace_fleet_display_values('fleet-vehicles',workspace_fleet_values_validated('fleet-vehicles',['A'=>$input])['values'])['A'],
        'trailer'=>workspace_fleet_plate_format(workspace_fleet_trailer_values(['H'=>$input])['plate'],true)??'',
        'vehicle-brand'=>mb_strtoupper(workspace_fleet_values_validated('fleet-vehicles',['A'=>'А000АА00','B'=>$input])['values']['B']),
        'unload-address'=>workspace_unload_validate_point(['A'=>'Учебный клиент А','B'=>$input],['client'=>'Учебный клиент А','client_id'=>1])['address'],
        'dropoff-address'=>workspace_dropoff_text($input,1500,true),
        'dropoff-instruction'=>workspace_dropoff_text($input,6000,true),
        'surname'=>workspace_fleet_values_validated('fleet-drivers',['A'=>$input,'B'=>'Иван'])['values']['A'],
        'given-name'=>workspace_fleet_values_validated('fleet-drivers',['A'=>'Примеров','B'=>$input])['values']['B'],
        'patronymic'=>workspace_fleet_values_validated('fleet-drivers',['A'=>'Примеров','B'=>'Иван','C'=>$input])['values']['C'],
        'text'=>$input,
        default=>throw new LogicException('Unknown example format: '.$type),
    };
}

function preview_single_line(string $value): string {
    vitrine_validate_single_line($value,['singleLine'=>['A'=>true]],'A');
    return $value;
}

function preview_clipboard_type(array $sample): string {
    return match ($sample['type']) {
        'expense','minimum'=>'money',
        'rule-date'=>'date',
        'line','document-line','dropoff-address','dropoff-instruction','unload-address'=>'text',
        default=>$sample['type'],
    };
}

function preview_calendar(array $sample,DateTimeImmutable $today): ?array {
    $kind=isset($sample['dropoff']) ? 'dropoff' : $sample['type'];
    $bounds=match ($kind) {
        'date'=>[$today->modify('-10 days')->format('Y-m-d'),$today->modify('+60 days')->format('Y-m-d')],
        'slot'=>[$today->format('Y-m-d'),$today->modify('+30 days')->format('Y-m-d')],
        'rule-date','dropoff'=>['2000-01-01','2099-12-31'],
        'birth-date'=>['1900-01-01',$today->format('Y-m-d')],
        'license-issued'=>['1900-01-01','2031-08-11'],
        'license-date'=>['2021-08-11','9999-12-31'],
        default=>null,
    };
    return $bounds===null ? null : ['kind'=>$kind,'min'=>$bounds[0],'max'=>$bounds[1],'required'=>$kind==='rule-date'];
}
