<?php
declare(strict_types=1);
?>
<tbody id="vitrine-draft-rows" aria-label="Новые записи справочника"
       data-draft-fields="<?= $escape(wjson($catalogGrid['draftFields'])) ?>">
<?php
(static function (array $columns, array $fields, Closure $escape, Closure $columnClass): void {
    $template = ['rowLabel'=>'','draftSlot'=>true,
        'singleLine'=>array_map(static fn(array $field): bool=>$field['singleLine'] ?? true,$fields),
        'choices'=>array_map(static fn(array $field): array=>$field['choices'] ?? [],$fields),
        'draftTypes'=>array_map(static fn(array $field): string=>$field['type'],$fields)];
    $rows = array_map(static fn(int $slot): array=>$template+['rowIndex'=>-$slot],[1,2,3]);
    require __DIR__.'/vitrine-rows.php';
})($columns,$catalogGrid['draftFields'],$escape,$columnClass);
?>
</tbody>
