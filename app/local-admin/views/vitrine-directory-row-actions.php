<?php
declare(strict_types=1);
?>
<div id="directory-row-menu" class="ctx-menu vitrine-directory-row-menu" data-directory-row-actions<?= $catalogGrid['archive'] ? ' data-archive' : '' ?> role="menu" aria-label="Действия со строкой" hidden>
  <button class="ctx-item is-danger" type="button" role="menuitem" data-directory-delete><?= $catalogGrid['archive'] ? 'Восстановить строку' : 'Удалить строку' ?></button>
</div>
