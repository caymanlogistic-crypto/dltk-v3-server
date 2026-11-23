<?php
declare(strict_types=1);
?>
<dialog class="vitrine-catalog-dialog" data-clear-cell-dialog aria-labelledby="vitrine-clear-cell-title">
  <form>
    <header class="modal-head"><h2 class="modal-title" id="vitrine-clear-cell-title">Очистить ячейку?</h2></header>
    <div class="modal-body"><p>Поле <strong data-clear-cell-label></strong> и его зависимые значения будут очищены.</p></div>
    <footer class="modal-foot">
      <button class="btn btn-primary" type="submit">Очистить</button>
      <button class="btn btn-secondary" type="button" data-clear-cell-cancel autofocus>Отмена</button>
    </footer>
  </form>
</dialog>
