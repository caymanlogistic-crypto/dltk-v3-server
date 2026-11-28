<?php
declare(strict_types=1);
?>
<?php if ($tripDraft): ?>
<tbody data-trip-drafts<?= isset($tripDraft['compactInputs']['F']) && !isset($tripDraft['readOnly']['F']) ? ' data-trip-day-drafts' : '' ?> aria-label="Новые рейсы">
<?php else: ?>
<tbody id="vitrine-draft-rows" aria-label="Новые записи справочника"
       data-draft-version="<?= $escape($catalogGrid['draftVersion'] ?? '') ?>"
       data-draft-fields="<?= $escape(wjson($catalogGrid['draftFields'])) ?>">
<?php endif; ?>
<?php
(static function (array $columns, ?array $tripDraft, array $fields, bool $passwordEye, Closure $escape, Closure $columnClass): void {
    $template = $tripDraft ?? ['rowLabel'=>'','draftSlot'=>true,
        'readOnly'=>array_fill_keys(array_column(array_filter($columns,static fn(array $column): bool=>($column['export']??true)===false),'letter'),
            'Сначала сохраните машину и укажите водителя.'),
        'singleLine'=>array_map(static fn(array $field): bool=>$field['singleLine'] ?? true,$fields),
        'choices'=>array_map(static fn(array $field): array=>$field['choices'] ?? [],$fields),
        'draftTypes'=>array_map(static fn(array $field): string=>$field['type'],$fields)];
    $count = $tripDraft ? WORKSPACE_AVTOVYVOZ_DRAFT_ROWS : 3;
    $rows = array_map(static fn(int $slot): array=>array_replace($template,[
        'rowIndex'=>-$slot,'rowLabel'=>'','draftSlot'=>true,'tripDraft'=>$tripDraft!==null,
    ]),range(1,$count));
    $catalogGrid = $tripDraft ? null : ['passwordEye'=>$passwordEye,'archive'=>false,'canEdit'=>true];
    require __DIR__.'/vitrine-rows.php';
})($columns,$tripDraft,$catalogGrid['draftFields']??[],!empty($catalogGrid['passwordEye']),$escape,$columnClass);
?>
</tbody>
