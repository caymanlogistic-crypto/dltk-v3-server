<?php
declare(strict_types=1);
?>
<dialog class="vitrine-catalog-dialog" data-directory-import-dialog aria-labelledby="directory-import-title">
  <form class="vitrine-directory-import-form">
    <header class="modal-head"><h2 class="modal-title" id="directory-import-title">Обновить справочник из Excel</h2></header>
    <div class="modal-body">
      <p>Скачайте шаблон с активными записями. Сохраните ID и версию существующих записей. Для новых строк оставьте эти поля пустыми.</p>
      <div><button class="btn btn-secondary" type="button" data-import-template>Скачать шаблон</button></div>
      <label class="field"><span class="field-label">Файл Excel (.xlsx)</span>
        <input name="workbook" class="field-input" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
      </label>
      <label class="checkbox-item"><input type="checkbox" name="replace"> Перенести в архив активные записи, которых нет в файле</label>
      <p data-import-result role="status" hidden></p>
    </div>
    <footer class="modal-foot">
      <button class="btn btn-primary" type="submit" data-import-submit>Проверить файл</button>
      <button class="btn btn-secondary" type="button" data-import-close>Отмена</button>
    </footer>
  </form>
</dialog>
