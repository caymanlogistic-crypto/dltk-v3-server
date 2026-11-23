<?php
declare(strict_types=1);
?>
<dialog class="vitrine-catalog-dialog" data-directory-import-dialog aria-labelledby="directory-import-title" aria-describedby="directory-import-warning">
  <form class="vitrine-directory-import-form">
    <header class="modal-head"><h2 class="modal-title" id="directory-import-title">Загрузка Excel</h2></header>
    <div class="modal-body">
      <div class="vitrine-import-file">
        <input name="workbook" type="file" accept=".xlsx" hidden>
        <button class="btn btn-secondary" type="button" data-import-pick autofocus>Выбрать .xlsx</button>
        <span data-import-filename role="status">Файл не выбран</span>
      </div>
      <p class="vitrine-import-warning" id="directory-import-warning"><strong>Полная замена справочника.</strong> После подтверждения в активном списке будут только строки из Excel, в том же порядке. Остальные записи уйдут в архив.</p>
      <p data-import-result role="status" hidden></p>
    </div>
    <footer class="modal-foot">
      <button class="btn btn-primary" type="submit" data-import-submit disabled>Проверить файл</button>
      <button class="btn btn-secondary" type="button" data-import-close>Отмена</button>
    </footer>
  </form>
</dialog>
