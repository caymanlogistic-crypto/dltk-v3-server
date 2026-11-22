<?php
declare(strict_types=1);
$archived = $catalogGrid['archive'];
$editable = $catalogGrid['canEdit'];
?>
<section class="vitrine-catalog-toolbar" aria-label="Управление справочником"
         data-catalog-id="<?= (int)$catalogGrid['id'] ?>"<?= $archived ? ' data-archive' : '' ?>>
  <h1>Клиенты</h1>
  <nav class="vitrine-catalog-tabs" aria-label="Записи справочника">
    <a class="btn" href="<?= $escape($viewUrl('/vitrine?catalog=clients')) ?>"<?= !$archived ? ' aria-current="page"' : '' ?>>Активные</a>
    <a class="btn" href="<?= $escape($viewUrl('/vitrine?catalog=clients&archive=1')) ?>"<?= $archived ? ' aria-current="page"' : '' ?>>Архив</a>
  </nav>
  <form class="vitrine-catalog-search" method="get" action="<?= $escape($viewUrl('/vitrine')) ?>">
    <input type="hidden" name="catalog" value="clients">
    <?php if ($archived): ?><input type="hidden" name="archive" value="1"><?php endif; ?>
    <input class="field-input" type="search" name="q" maxlength="160" value="<?= $escape($catalogGrid['search']) ?>"
           aria-label="Поиск по клиенту или менеджеру" placeholder="Клиент или менеджер">
    <button class="btn" type="submit">Найти</button>
  </form>
  <?php if ($editable && !$archived && $catalogGrid['syncLabel']): ?>
    <button class="btn" type="button" id="client-catalog-sync"><?= $escape($catalogGrid['syncLabel']) ?></button>
  <?php endif; ?>
</section>
