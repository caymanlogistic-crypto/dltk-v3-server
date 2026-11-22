<?php declare(strict_types=1); ?>
<dialog class="vitrine-editor vitrine-client-unload-dialog" id="client-unload-dialog" aria-labelledby="client-unload-title">
  <h2 class="vitrine-editor-title" id="client-unload-title">Точки выгрузки</h2>
  <section data-client-unload-list>
    <div class="vitrine-client-unload-scroll" data-client-unload-scroll>
      <table class="vitrine-client-unload-table" aria-label="Точки выгрузки клиента">
        <thead><tr><th scope="col" aria-label="Действия"></th><th scope="col">Адрес</th><th scope="col">Контакты</th></tr></thead>
        <tbody data-client-unload-rows></tbody>
      </table>
    </div>
    <div class="vitrine-editor-actions">
      <button class="btn btn-primary" type="button" data-client-unload-add hidden>Добавить точку</button>
      <button class="btn btn-secondary" type="button" data-client-unload-close autofocus>Закрыть</button>
    </div>
  </section>
  <form data-client-unload-form hidden>
    <label class="vitrine-editor-field-group"><span class="field-label">Адрес выгрузки</span><textarea class="field-input vitrine-editor-field" name="address" rows="3" maxlength="6000" required></textarea></label>
    <div class="vitrine-editor-actions">
      <button class="btn btn-primary" type="submit">Сохранить</button>
      <button class="btn btn-secondary" type="button" data-client-unload-cancel>Отмена</button>
    </div>
  </form>
  <form data-client-unload-delete hidden>
    <p data-client-unload-confirm></p>
    <div class="vitrine-editor-actions">
      <button class="btn btn-primary" type="submit">Удалить</button>
      <button class="btn btn-secondary" type="button" data-client-unload-cancel>Отмена</button>
    </div>
  </form>
  <div class="ctx-menu vitrine-directory-row-menu" data-client-unload-menu role="menu" aria-label="Действия с точкой" hidden>
    <button class="ctx-item is-danger" type="button" role="menuitem" data-client-unload-remove>Удалить строку</button>
  </div>
</dialog>
