<?php declare(strict_types=1); ?>
<dialog class="vitrine-catalog-dialog vitrine-client-unload-dialog" id="client-unload-dialog" aria-labelledby="client-unload-title">
  <header class="modal-head"><h2 class="modal-title" id="client-unload-title">Точки выгрузки</h2></header>
  <section data-client-unload-list>
    <div class="modal-body" data-client-unload-rows></div>
    <footer class="modal-foot">
      <button class="btn btn-primary" type="button" data-client-unload-add hidden>Добавить точку</button>
      <button class="btn btn-secondary" type="button" data-client-unload-close autofocus>Закрыть</button>
    </footer>
  </section>
  <form data-client-unload-form hidden>
    <div class="modal-body">
      <label class="field"><span class="field-label">Адрес выгрузки</span><textarea class="field-input" name="address" rows="3" maxlength="6000" required></textarea></label>
    </div>
    <footer class="modal-foot">
      <button class="btn btn-primary" type="submit">Сохранить адрес</button>
      <button class="btn btn-secondary" type="button" data-client-unload-cancel>Отмена</button>
    </footer>
  </form>
  <form data-client-unload-delete hidden>
    <div class="modal-body"><p data-client-unload-confirm></p></div>
    <footer class="modal-foot">
      <button class="btn btn-primary" type="submit">Удалить точку</button>
      <button class="btn btn-secondary" type="button" data-client-unload-cancel>Отмена</button>
    </footer>
  </form>
</dialog>
