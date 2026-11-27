<?php
declare(strict_types=1);
$employeePage = $directory === 'employees';
$employeeScope = $employeePage ? workspace_employee_editor_scope() : null;
$archive = $catalogGrid['archive'];
$canUploadExcel = !$embeddedCatalog && $catalogGrid['canEdit'] && !$archive && workspace_directory_excel_supported($directory);
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
$searchLabel = $directory === 'clients' ? 'Поиск по клиенту, ИНН или менеджеру' : 'Поиск в справочнике';
$unloadClientName = !empty($catalogGrid['unloadPoints']) ? ($catalogGrid['clientFilter']['name']??null) : null;
?>
<section class="vitrine-catalog-toolbar" aria-label="Управление справочником"<?= isset($catalogGrid['id']) ? ' data-catalog-id="'.(int)$catalogGrid['id'].'"' : '' ?>>
  <?php if ($unloadClientName!==null): ?>
  <h1><strong>Точки выгрузки: <?= $escape($unloadClientName) ?></strong></h1>
  <?php else: ?>
  <h1>Справочник: <strong><?= $escape($currentTable['title']) ?></strong><?php if ($visibilityLabel): ?> (<span data-directory-row-count><?= count($data['rowIds'] ?? []) ?></span>)<?php endif; ?></h1>
  <?php endif; ?>
  <?php if (!$embeddedCatalog && ($currentTable['catalogKind']??null)==='terminals-main'): ?>
  <a class="btn btn-secondary" href="<?= $escape($viewUrl($directoryUrl.'&tariffs=1')) ?>">Тарифы</a>
  <?php endif; ?>
  <?php if (!empty($catalogGrid['clientFilter'])): ?>
  <?php if ($unloadClientName===null): ?><span>Клиент: <strong><?= $escape($catalogGrid['clientFilter']['name']) ?></strong></span><?php endif; ?>
  <?php if (!$embeddedCatalog): ?><a href="<?= $escape($viewUrl($directoryUrl.($archive ? '&archive=1' : (!empty($catalogGrid['showAll']) ? '&archive=all' : '')))) ?>"><?= $directory==='clients' ? 'Сбросить выбор клиента' : 'Все точки выгрузки' ?></a><?php endif; ?>
  <?php endif; ?>
  <form class="vitrine-catalog-search" method="get" action="<?= $escape($viewUrl('/vitrine')) ?>">
    <input type="hidden" name="catalog" value="<?= $escape($directory) ?>">
    <?php if ($directory === 'entries'): ?><input type="hidden" name="id" value="<?= (int)$catalogGrid['id'] ?>"><?php endif; ?>
    <?php if ($directory === 'flight-statuses'): ?><input type="hidden" name="table" value="<?= $escape($managementTable) ?>"><?php endif; ?>
    <?php if ($archive): ?><input type="hidden" name="archive" value="1"><?php endif; ?>
    <?php if (!empty($catalogGrid['showAll'])): ?><input type="hidden" name="archive" value="all"><?php endif; ?>
    <?php if (!empty($catalogGrid['clientFilter'])): ?><input type="hidden" name="client" value="<?= (int)$catalogGrid['clientFilter']['id'] ?>"><?php endif; ?>
    <?php require __DIR__.'/vitrine-archive-filter.php'; ?>
    <?php if (!empty($catalogGrid['partyRoles'])): ?>
    <div class="vitrine-directory-visibility" data-party-role="<?= $escape($catalogGrid['partyRole']) ?>"
         data-party-roles="<?= $escape(wjson($catalogGrid['partyRoles'])) ?>"></div>
    <?php endif; ?>
    <input class="field-input" type="search" name="q" maxlength="160" value="<?= $escape($catalogGrid['search']) ?>"
           aria-label="<?= $escape($searchLabel) ?>" placeholder="<?= $escape($searchPlaceholder) ?>">
    <?php if (!$embeddedCatalog) require __DIR__.'/vitrine-export-button.php'; ?>
    <?php if ($canUploadExcel): ?>
    <button class="btn btn-secondary btn-icon vitrine-excel-button" type="button" data-directory-import
            aria-label="Загрузить Excel">
      <svg aria-hidden="true" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 15V3m-4 4 4-4 4 4M4 16v4h16v-4"/>
      </svg>
    </button>
    <?php endif; ?>
  </form>
  <?php if ($catalogGrid['canEdit'] && !$archive && !empty($catalogGrid['syncLabel'])): ?>
  <button class="btn" type="button" id="client-catalog-sync"><?= $escape($catalogGrid['syncLabel']) ?></button>
  <?php endif; ?>
  <?php if (!empty($catalogGrid['standaloneCard'])): ?>
  <button class="btn" type="button" data-standalone-card-open>Транспорт перевозчика</button>
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
<?php if ($canUploadExcel) require __DIR__.'/vitrine-directory-import.php'; ?>
<?php if ($employeeScope): ?>
<dialog class="vitrine-catalog-dialog" id="employee-dialog" data-roles="<?= $escape(wjson(workspace_employee_role_options($employeeScope))) ?>" aria-labelledby="employee-dialog-title">
  <form>
    <header class="modal-head"><h2 class="modal-title" id="employee-dialog-title">Сотрудник</h2></header>
    <div class="modal-body">
      <label class="field"><span class="field-label">ФИО</span><input class="field-input" name="name" maxlength="160" required autofocus></label>
      <label class="field"><span class="field-label">Логин</span><input class="field-input" name="login" maxlength="60" required autocomplete="off"></label>
      <div class="field">
        <label class="field-label" id="employee-password-label" for="employee-password">Пароль</label>
        <div class="auth-password">
          <input class="field-input" id="employee-password" name="password" type="password" autocomplete="new-password" minlength="8" maxlength="72">
          <?php $passwordEyeField='employee-password'; require __DIR__.'/password-eye.php'; ?>
        </div>
      </div>
      <label class="field"><span class="field-label">Email</span><input class="field-input" name="email" type="email" maxlength="254" autocomplete="off"></label>
      <div class="field"><span class="field-label" id="employee-role-label">Роль</span><div data-employee-role></div></div>
      <label class="checkbox-item"><input type="checkbox" name="active"> Учётная запись активна</label>
      <p data-employee-error role="alert" hidden></p>
    </div>
    <footer class="modal-foot"><button class="btn btn-primary" type="submit">Сохранить</button><button class="btn btn-secondary" type="button" data-close>Отмена</button></footer>
  </form>
</dialog>
<template id="vitrine-password-eye">
  <?php $passwordEyeField='vitrine-editor-field'; require __DIR__.'/password-eye.php'; ?>
</template>
<?php endif; ?>

<?php if ($directory === 'entries' && !empty($catalogGrid['hasDetails'])): ?>
<dialog class="vitrine-catalog-dialog" data-directory-details-dialog aria-labelledby="directory-details-title">
  <header class="modal-head"><h2 class="modal-title" id="directory-details-title">Реквизиты записи</h2></header>
  <div class="modal-body"></div>
  <footer class="modal-foot"><button class="btn btn-secondary" type="button" data-close autofocus>Закрыть</button></footer>
</dialog>
<?php endif; ?>

<?php if (!empty($catalogGrid['standaloneCard'])) require __DIR__.'/vitrine-standalone-card.php'; ?>
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
