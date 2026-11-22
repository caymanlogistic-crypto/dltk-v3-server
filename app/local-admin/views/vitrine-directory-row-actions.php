<?php
declare(strict_types=1);
?>
<div id="directory-row-menu" class="ctx-menu vitrine-directory-row-menu" data-directory-row-actions<?= $catalogGrid['archive'] ? ' data-archive' : '' ?> role="menu" aria-label="Действия со строкой" hidden>
  <?php if (!empty($catalogGrid['relatedLabel'])): ?>
  <a class="ctx-item" role="menuitem" data-directory-related hidden><?= $escape($catalogGrid['relatedLabel']) ?></a>
  <?php endif; ?>
  <?php if ($canManageDirectory): ?>
  <button class="ctx-item is-danger" type="button" role="menuitem" data-directory-delete><?= $catalogGrid['archive'] ? 'Восстановить строку' : 'Удалить строку' ?></button>
  <?php endif; ?>
</div>
