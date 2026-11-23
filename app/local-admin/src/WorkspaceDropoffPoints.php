<?php
declare(strict_types=1);

function workspace_dropoff_profiles(int $catalogId): array {
    $profiles=[];
    foreach (wquery("SELECT e.id,m.value FROM catalog_entries e
        JOIN workspace_meta m ON m.`key`=CONCAT('dropoff_point_',e.id)
        WHERE e.catalog_id=?",[$catalogId])->fetchAll() as $row) {
        $profiles[(int)$row['id']]=workspace_dropoff_profile(json_decode($row['value'],true)??[]);
    }
    return $profiles;
}

function workspace_dropoff_text(mixed $value,int $limit,bool $multiline=false): string {
    if (!is_string($value) || mb_strlen($value)>$limit ||
        preg_match($multiline ? '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u' : '/[\x00-\x1F]/u',$value)) {
        throw new ApiError('invalid_dropoff','Проверьте длину и формат данных сдачи.');
    }
    return trim($value);
}

function workspace_dropoff_profile(array $values): array {
    return [
        'B'=>workspace_dropoff_text($values['B']??'',1500,true),
        'C'=>workspace_dropoff_text($values['C']??'',1500,true),
        'D'=>workspace_dropoff_text($values['D']??'',6000,true),
    ];
}

function workspace_dropoff_flight_details(mixed $input): array {
    if (!is_array($input)) throw new ApiError('invalid_dropoff','Проверьте данные сдачи по рейсу.');
    $date=workspace_dropoff_text($input['validUntil']??'',10);
    $validUntil=$date==='' ? '' : vitrine_compact_input($date,'date');
    if ($validUntil===null) throw new ApiError('invalid_dropoff_date','Укажите действительную дату в формате ДД.ММ.ГГ.');
    return [
        'release'=>workspace_dropoff_text($input['release']??'',160),
        'booking'=>workspace_dropoff_text($input['booking']??'',160),
        'validUntil'=>$validUntil,
    ];
}

function workspace_dropoff_update_flight(array &$values,array $body,string $previous,bool $hadPoint): array {
    $old=is_array($values['dropoffDetails']??null) ? $values['dropoffDetails'] : [];
    if (!array_key_exists('dropoffDetails',$body)) return [$old,$old];
    $next=$body['dropoffDetails'];
    if (isset($old['legacy'])) $next['legacy']=$old['legacy'];
    elseif (!$hadPoint && $previous!=='') $next['legacy']=$previous;
    $values['dropoffDetails']=$next;
    return [$old,$next];
}
