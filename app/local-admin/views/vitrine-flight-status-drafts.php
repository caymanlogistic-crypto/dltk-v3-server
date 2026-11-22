<?php
declare(strict_types=1);
?>
<tbody id="vitrine-draft-rows" aria-label="Новые статусы рейса">
<?php
(static function (array $columns, array $palette, Closure $escape, Closure $columnClass): void {
    $choices = array_map(static fn(array $color): array => [
        'value'=>$color['name'], 'label'=>$color['name'], 'color'=>$color['fill'],
    ], $palette);
    $rows = array_map(static fn(int $slot): array => [
        'rowIndex'=>-$slot, 'rowLabel'=>'', 'draftSlot'=>true,
        'singleLine'=>['A'=>true], 'choices'=>['B'=>$choices],
    ], [1, 2, 3]);
    require __DIR__.'/vitrine-rows.php';
})($columns, $data['palette'], $escape, $columnClass);
?>
</tbody>
