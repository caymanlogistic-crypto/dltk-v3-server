<?php declare(strict_types=1); ?>
<dialog class="vitrine-editor vitrine-client-relations-dialog" id="client-managers-dialog" aria-labelledby="client-managers-title">
  <h2 class="vitrine-editor-title" id="client-managers-title">Менеджеры клиента</h2>
  <section data-client-manager-list>
    <div class="vitrine-client-relations-scroll" data-client-manager-scroll>
      <table class="vitrine-client-relations-table" aria-label="Менеджеры клиента">
        <thead><tr><th scope="col" aria-label="Действия"></th><th scope="col">Менеджер</th></tr></thead>
        <tbody data-client-manager-rows></tbody>
      </table>
    </div>
    <div class="vitrine-editor-actions">
      <button class="btn btn-primary" type="button" data-client-manager-add hidden>Добавить менеджера</button>
      <button class="btn btn-secondary" type="button" data-client-manager-close autofocus>Закрыть</button>
    </div>
  </section>
  <form data-client-manager-form hidden>
    <div class="vitrine-editor-field-group">
      <span class="field-label" id="client-manager-label">Менеджер</span>
      <div data-client-manager-picker></div>
    </div>
    <div class="vitrine-editor-actions">
      <button class="btn btn-primary" type="submit">Сохранить</button>
      <button class="btn btn-secondary" type="button" data-client-manager-cancel>Отмена</button>
    </div>
  </form>
  <form data-client-manager-remove-form hidden>
    <p data-client-manager-confirm></p>
    <div class="vitrine-editor-actions">
      <button class="btn btn-primary" type="submit">Удалить</button>
      <button class="btn btn-secondary" type="button" data-client-manager-cancel>Отмена</button>
    </div>
  </form>
  <div class="ctx-menu vitrine-directory-row-menu" data-client-manager-menu role="menu" aria-label="Действия с менеджером" hidden>
    <button class="ctx-item is-danger" type="button" role="menuitem" data-client-manager-remove>Удалить строку</button>
  </div>
</dialog>
