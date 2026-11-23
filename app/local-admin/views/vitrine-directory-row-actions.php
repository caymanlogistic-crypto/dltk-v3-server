<?php
declare(strict_types=1);
?>
<div id="directory-row-menu" class="ctx-menu vitrine-directory-row-menu" data-directory-row-actions<?= $catalogGrid['archive'] ? ' data-archive' : '' ?><?= !empty($catalogGrid['archiveRows']) ? ' data-archive-rows' : '' ?> role="menu" aria-label="Действия со строкой" hidden>
  <?php if (!empty($catalogGrid['relatedLabel'])): ?>
  <a class="ctx-item" role="menuitem" data-directory-related hidden><?= $escape($catalogGrid['relatedLabel']) ?></a>
  <?php endif; ?>
  <?php if ($canManageDirectory): ?>
  <?php if ($directory === 'clients'): ?>
  <button class="ctx-item" type="button" role="menuitem" data-directory-archive-toggle></button>
  <button class="ctx-item is-danger" type="button" role="menuitem" data-directory-delete>Удалить строку</button>
  <?php else: ?>
  <button class="ctx-item<?= empty($catalogGrid['archiveRows']) ? ' is-danger' : '' ?>" type="button" role="menuitem" data-directory-delete><?= $catalogGrid['archive'] ? 'Восстановить строку' : (!empty($catalogGrid['archiveRows']) ? 'В архив' : 'Удалить строку') ?></button>
  <?php endif; ?>
  <?php endif; ?>
</div>
