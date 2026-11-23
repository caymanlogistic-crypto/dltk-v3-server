<?php
declare(strict_types=1);
$employeePage = $directory === 'employees';
$employeeScope = $employeePage ? workspace_employee_editor_scope() : null;
$archive = $catalogGrid['archive'];
$staffDirectory = ($currentTable['catalogKind'] ?? '') === 'managers';
$visibilityLabel = $staffDirectory ? 'Все сотрудники' : ($directory === 'clients' ? 'Все клиенты' : null);
$visibilityAria = $staffDirectory ? 'Фильтр сотрудников' : ($directory === 'clients' ? 'Фильтр клиентов' : 'Фильтр записей');
$searchPlaceholder = match ($directory) {
    'clients'=>'Клиент или менеджер',
    'employees'=>'ФИО, логин, email или роль',
    'roles'=>'Название роли',
    'entries'=>'Поиск по справочнику',
    default=>'Название статуса',
};
$searchLabel = $directory === 'clients' ? 'Поиск по клиенту или менеджеру' : 'Поиск в справочнике';
?>
<section class="vitrine-catalog-toolbar" aria-label="Управление справочником"<?= isset($catalogGrid['id']) ? ' data-catalog-id="'.(int)$catalogGrid['id'].'"' : '' ?>>
  <h1>Справочник: <strong><?= $escape($currentTable['title']) ?></strong><?php if ($visibilityLabel): ?> (<span data-directory-row-count><?= count($data['rowIds'] ?? []) ?></span>)<?php endif; ?></h1>
  <?php if (!empty($catalogGrid['clientFilter'])): ?>
  <span>Клиент: <strong><?= $escape($catalogGrid['clientFilter']['name']) ?></strong></span>
  <a href="<?= $escape($viewUrl($directoryUrl.($archive ? '&archive=1' : (!empty($catalogGrid['showAll']) ? '&archive=all' : '')))) ?>"><?= $directory==='clients' ? 'Сбросить выбор клиента' : 'Все точки выгрузки' ?></a>
  <?php endif; ?>
  <form class="vitrine-catalog-search" method="get" action="<?= $escape($viewUrl('/vitrine')) ?>">
    <input type="hidden" name="catalog" value="<?= $escape($directory) ?>">
    <?php if ($directory === 'entries'): ?><input type="hidden" name="id" value="<?= (int)$catalogGrid['id'] ?>"><?php endif; ?>
    <?php if ($directory === 'flight-statuses'): ?><input type="hidden" name="table" value="<?= $escape($managementTable) ?>"><?php endif; ?>
    <?php if ($archive): ?><input type="hidden" name="archive" value="1"><?php endif; ?>
    <?php if (!empty($catalogGrid['showAll'])): ?><input type="hidden" name="archive" value="all"><?php endif; ?>
    <?php if (!empty($catalogGrid['clientFilter'])): ?><input type="hidden" name="client" value="<?= (int)$catalogGrid['clientFilter']['id'] ?>"><?php endif; ?>
    <?php require __DIR__.'/vitrine-archive-filter.php'; ?>
    <input class="field-input" type="search" name="q" maxlength="160" value="<?= $escape($catalogGrid['search']) ?>"
           aria-label="<?= $escape($searchLabel) ?>" placeholder="<?= $escape($searchPlaceholder) ?>">
    <?php require __DIR__.'/vitrine-export-button.php'; ?>
  </form>
  <?php if ($catalogGrid['canEdit'] && !$archive && !empty($catalogGrid['syncLabel'])): ?>
  <button class="btn" type="button" id="client-catalog-sync"><?= $escape($catalogGrid['syncLabel']) ?></button>
  <?php endif; ?>
  <?php if ($directory === 'entries' && !empty($catalogGrid['hasDetails'])): ?>
  <button class="btn" type="button" data-directory-details>Реквизиты</button>
  <?php if (!empty($catalogGrid['unkeyedCount'])): ?>
  <button class="btn" type="button" data-directory-details data-unkeyed>Строки без названия (<?= (int)$catalogGrid['unkeyedCount'] ?>)</button>
  <?php endif; ?>
  <?php endif; ?>
  <?php if ($employeeScope): ?>
  <div class="vitrine-catalog-actions">
    <button class="btn btn-secondary" type="button" id="employee-edit" disabled>Изменить сотрудника</button>
  </div>
  <?php endif; ?>
</section>
<?php if ($employeeScope): ?>
<dialog class="vitrine-catalog-dialog" id="employee-dialog" data-roles="<?= $escape(wjson(workspace_employee_role_options($employeeScope))) ?>" aria-labelledby="employee-dialog-title">
  <form>
    <header class="modal-head"><h2 class="modal-title" id="employee-dialog-title">Сотрудник</h2></header>
    <div class="modal-body">
      <label class="field"><span class="field-label">ФИО</span><input class="field-input" name="name" maxlength="160" required autofocus></label>
      <label class="field"><span class="field-label">Логин</span><input class="field-input" name="login" maxlength="60" required autocomplete="off"></label>
      <label class="field"><span class="field-label" id="employee-password-label">Пароль</span><input class="field-input" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="72"></label>
      <label class="field"><span class="field-label">Email</span><input class="field-input" name="email" type="email" maxlength="254" autocomplete="off"></label>
      <div class="field"><span class="field-label" id="employee-role-label">Роль</span><div data-employee-role></div></div>
      <label class="checkbox-item"><input type="checkbox" name="active"> Учётная запись активна</label>
      <p data-employee-error role="alert" hidden></p>
    </div>
    <footer class="modal-foot"><button class="btn btn-primary" type="submit">Сохранить</button><button class="btn btn-secondary" type="button" data-close>Отмена</button></footer>
  </form>
</dialog>
<?php endif; ?>

<?php if ($directory === 'entries' && !empty($catalogGrid['hasDetails'])): ?>
<dialog class="vitrine-catalog-dialog" data-directory-details-dialog aria-labelledby="directory-details-title">
  <header class="modal-head"><h2 class="modal-title" id="directory-details-title">Реквизиты записи</h2></header>
  <div class="modal-body"></div>
  <footer class="modal-foot"><button class="btn btn-secondary" type="button" data-close autofocus>Закрыть</button></footer>
</dialog>
<?php endif; ?>

<?php if (!empty($catalogGrid['clientUnloadCatalog'])) require __DIR__.'/vitrine-client-unload-dialog.php'; ?>
<?php if (!empty($catalogGrid['clientManagers'])) require __DIR__.'/vitrine-client-managers-dialog.php'; ?>
<?php if (!empty($catalogGrid['unloadPoints'])): ?>
<dialog class="vitrine-catalog-dialog" id="unload-contacts-dialog" data-catalog-id="<?= (int)$catalogGrid['id'] ?>" aria-labelledby="unload-contacts-title">
  <form>
    <header class="modal-head"><h2 class="modal-title" id="unload-contacts-title">Контакты точки</h2></header>
    <div class="modal-body">
      <div data-unload-contact-list></div>
      <?php if ($canManageDirectory && !$archive): ?>
      <button class="btn btn-secondary" type="button" data-unload-contact-add>Добавить контакт</button>
      <?php endif; ?>
    </div>
    <footer class="modal-foot">
      <?php if ($canManageDirectory && !$archive): ?><button class="btn btn-primary" type="submit">Сохранить</button><?php endif; ?>
      <button class="btn btn-secondary" type="button" data-close>Закрыть</button>
    </footer>
  </form>
</dialog>
<?php endif; ?>
