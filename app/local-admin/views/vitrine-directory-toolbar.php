<?php
declare(strict_types=1);
$employeePage = $directory === 'employees';
$employeeScope = $employeePage ? workspace_employee_editor_scope() : null;
$archive = $catalogGrid['archive'];
?>
<section class="vitrine-catalog-toolbar" aria-label="Управление справочником">
  <h1><?= $escape($currentTable['title']) ?></h1>
  <?php if ($employeePage): ?>
  <nav class="vitrine-catalog-tabs" aria-label="Записи справочника">
    <a class="btn" href="<?= $escape($viewUrl('/vitrine?catalog=employees')) ?>"<?= !$archive ? ' aria-current="page"' : '' ?>>Активные</a>
    <a class="btn" href="<?= $escape($viewUrl('/vitrine?catalog=employees&archive=1')) ?>"<?= $archive ? ' aria-current="page"' : '' ?>>Архив</a>
  </nav>
  <?php endif; ?>
  <form class="vitrine-catalog-search" method="get" action="<?= $escape($viewUrl('/vitrine')) ?>">
    <input type="hidden" name="catalog" value="<?= $escape($directory) ?>">
    <?php if ($directory === 'flight-statuses'): ?><input type="hidden" name="table" value="<?= $escape($managementTable) ?>"><?php endif; ?>
    <?php if ($archive): ?><input type="hidden" name="archive" value="1"><?php endif; ?>
    <input class="field-input" type="search" name="q" maxlength="160" value="<?= $escape($catalogGrid['search']) ?>"
           aria-label="Поиск в справочнике" placeholder="<?= $employeePage ? 'ФИО, логин, email или роль' : ($directory === 'roles' ? 'Название роли' : 'Название статуса') ?>">
    <button class="btn" type="submit">Найти</button>
  </form>
  <?php if ($directory === 'flight-statuses' && $catalogGrid['canEdit']): ?>
  <button class="btn btn-primary" type="button" id="vitrine-open-statuses" data-status-table="<?= $escape($managementTable) ?>" data-add-only>Добавить статус</button>
  <?php endif; ?>
  <?php if ($employeeScope): ?>
  <div class="vitrine-catalog-actions">
    <?php if (!$archive): ?><button class="btn btn-primary" type="button" id="employee-add">Добавить сотрудника</button><?php endif; ?>
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
