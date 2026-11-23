<?php
declare(strict_types=1);
?>
<dialog class="vitrine-catalog-dialog" id="fleet-documents-dialog" aria-labelledby="fleet-documents-title">
  <form autocomplete="off">
    <header class="modal-head"><h2 class="modal-title" id="fleet-documents-title">Документы водителя</h2></header>
    <div class="modal-body">
      <label class="field vitrine-editor-field-group">
        <span class="field-label">Паспортные данные</span>
        <input class="field-input" name="passport" maxlength="500">
      </label>
      <label class="field vitrine-editor-field-group">
        <span class="field-label">Серия и номер ВУ</span>
        <input class="field-input" name="license_number" maxlength="80">
      </label>
      <label class="field vitrine-editor-field-group">
        <span class="field-label">Дата выдачи ВУ</span>
        <input class="field-input" type="date" name="license_issued" min="1900-01-01">
      </label>
      <label class="field vitrine-editor-field-group">
        <span class="field-label">Действует до</span>
        <input class="field-input" type="date" name="license_expires" min="1900-01-01">
      </label>
    </div>
    <footer class="modal-foot">
      <button class="btn btn-primary" type="submit">Сохранить</button>
      <button class="btn btn-secondary" type="button" data-close>Закрыть</button>
    </footer>
  </form>
</dialog>
