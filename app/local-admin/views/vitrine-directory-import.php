<?php
declare(strict_types=1);
?>
<dialog class="vitrine-catalog-dialog" data-directory-import-dialog aria-labelledby="directory-import-title">
  <form class="vitrine-directory-import-form">
    <header class="modal-head"><h2 class="modal-title" id="directory-import-title">Загрузка Excel</h2></header>
    <div class="modal-body">
      <div class="vitrine-import-file">
        <input name="workbook" type="file" accept=".xlsx" hidden>
        <button class="btn btn-secondary" type="button" data-import-pick autofocus>Выбрать .xlsx</button>
        <span data-import-filename role="status">Файл не выбран</span>
      </div>
      <label class="checkbox-item vitrine-import-archive">
        <input type="checkbox" name="replace"><span class="checkbox-mark" aria-hidden="true"></span>
        <span>В архив записи, которых нет в файле</span>
      </label>
      <p data-import-result role="status" hidden></p>
    </div>
    <footer class="modal-foot">
      <button class="btn btn-primary" type="submit" data-import-submit disabled>Проверить файл</button>
      <button class="btn btn-secondary" type="button" data-import-close>Отмена</button>
    </footer>
  </form>
</dialog>
