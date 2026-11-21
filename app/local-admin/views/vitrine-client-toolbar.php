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
  <?php if ($editable): ?>
    <div class="vitrine-catalog-actions">
      <?php if (!$archived): ?>
      <button class="btn btn-primary" type="button" id="client-catalog-add">Добавить клиента</button>
      <?php if ($catalogGrid['syncLabel']): ?>
      <button class="btn" type="button" id="client-catalog-sync"><?= $escape($catalogGrid['syncLabel']) ?></button>
      <?php endif; ?>
      <?php endif; ?>
      <button class="btn" type="button" id="client-catalog-row-action" disabled><?= $archived ? 'Восстановить' : 'В архив' ?></button>
    </div>
  <?php endif; ?>
</section>
<?php if ($editable): ?>
<?php if (!$archived): ?>
<dialog class="vitrine-catalog-dialog" id="client-catalog-add-dialog" aria-labelledby="client-catalog-add-title">
  <form>
    <header class="modal-head"><h2 class="modal-title" id="client-catalog-add-title">Добавить клиента</h2></header>
    <div class="modal-body">
      <label class="field"><span class="field-label">Клиент</span><input class="field-input" name="name" maxlength="160" required autofocus></label>
      <label class="field"><span class="field-label">Менеджер</span><input class="field-input" name="manager" maxlength="160"></label>
    </div>
    <footer class="modal-foot"><button class="btn btn-primary" type="submit">Добавить</button><button class="btn" type="button" data-close>Отмена</button></footer>
  </form>
</dialog>
<?php endif; ?>
<dialog class="vitrine-catalog-dialog" id="client-catalog-row-dialog" aria-labelledby="client-catalog-row-title">
  <form>
    <header class="modal-head"><h2 class="modal-title" id="client-catalog-row-title"><?= $archived ? 'Восстановить клиента' : 'Убрать клиента в архив' ?></h2></header>
    <div class="modal-body">
      <strong data-client-name></strong>
      <p><?= $archived ? 'Клиент снова появится в списке выбора.' : 'Клиент исчезнет из списка выбора. Запись можно восстановить из архива.' ?></p>
    </div>
    <footer class="modal-foot"><button class="btn btn-primary" type="submit"><?= $archived ? 'Восстановить' : 'В архив' ?></button><button class="btn" type="button" data-close autofocus>Отмена</button></footer>
  </form>
</dialog>
<?php endif; ?>
