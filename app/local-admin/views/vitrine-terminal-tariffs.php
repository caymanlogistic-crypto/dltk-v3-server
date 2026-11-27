<?php
declare(strict_types=1);
$tariffs=$terminalTariffs;
$selectedTerminal=$tariffs['terminalOptions'][$tariffs['terminal']]??'';
$baseQuery=['catalog'=>'entries','id'=>(int)$catalogGrid['id'],'tariffs'=>'1','tariffDate'=>$tariffs['date'],
    'tariffSheet'=>$tariffs['sheet'],'tariffTerminal'=>$tariffs['terminal'],'tariffSize'=>$tariffs['size'],'tariffQuery'=>$tariffs['query']];
$pageUrl=static fn(int $index): string => $viewUrl('/vitrine?'.http_build_query($baseQuery+['tariffPage'=>$index]));
$filterOptions=[
    'tariffTerminal'=>['Терминал',array_map(static fn($value,$label): array => ['value'=>(string)$value,'label'=>$label],
        array_keys($tariffs['terminalOptions']),array_values($tariffs['terminalOptions'])),$tariffs['terminal'],true],
    'tariffSheet'=>['Раздел КП',array_map(static fn($value): array => ['value'=>$value,'label'=>$value],array_values($tariffs['sheetOptions'])),$tariffs['sheet'],false],
    'tariffSize'=>['Размер',array_map(static fn($value): array => ['value'=>$value,'label'=>$value.' футов'],['20','40','45']),$tariffs['size'],false],
];
?>
<section class="vitrine-catalog-toolbar" aria-label="Терминальные тарифы">
  <h1><?php if ($selectedTerminal!==''): ?>Тарифы терминала: <strong><?= $escape($selectedTerminal) ?></strong><?php else: ?>Тарифы терминалов<?php endif; ?></h1>
  <a class="btn btn-secondary" href="<?= $escape($viewUrl($directoryUrl)) ?>">Терминалы</a>
  <form class="vitrine-catalog-search" method="get" action="<?= $escape($viewUrl('/vitrine')) ?>" data-terminal-tariff-filters>
    <input type="hidden" name="catalog" value="entries">
    <input type="hidden" name="id" value="<?= (int)$catalogGrid['id'] ?>">
    <input type="hidden" name="tariffs" value="1">
    <label class="vitrine-directory-visibility">На дату <input class="field-input" type="date" name="tariffDate" value="<?= $escape($tariffs['date']) ?>" required></label>
    <?php foreach ($filterOptions as $param=>[$label,$options,$selected,$searchable]): ?>
      <div class="vitrine-directory-visibility" data-tariff-filter data-options="<?= $escape(wjson([['value'=>'','label'=>$label],...$options])) ?>"
           data-label="<?= $escape($label) ?>" data-searchable="<?= $searchable?'1':'0' ?>">
        <input type="hidden" name="<?= $escape($param) ?>" value="<?= $escape($selected) ?>">
      </div>
    <?php endforeach; ?>
    <input class="field-input" type="search" name="tariffQuery" maxlength="160" value="<?= $escape($tariffs['query']) ?>"
           aria-label="Услуга или условие" placeholder="Услуга или условие">
  </form>
</section>
<?php
$showDraftRows=false;
$rowArchive=false;
$catalogGrid['canEdit']=false;
require __DIR__.'/vitrine-hide-column-dialog.php';
require __DIR__.'/vitrine-table.php';
?>
<?php if ($tariffs['total']>0): ?>
<nav class="pagination-wrap" aria-label="Страницы ставок">
  <?php if ($tariffs['page']>1): ?><a class="btn" href="<?= $escape($pageUrl($tariffs['page']-1)) ?>">Назад</a><?php endif; ?>
  <span>Страница <?= (int)$tariffs['page'] ?> из <?= (int)$tariffs['pages'] ?> · <?= (int)$tariffs['total'] ?> позиций</span>
  <?php if ($tariffs['page']<$tariffs['pages']): ?><a class="btn" href="<?= $escape($pageUrl($tariffs['page']+1)) ?>">Далее</a><?php endif; ?>
</nav>
<?php endif; ?>
