<?php
declare(strict_types=1);
?>
<div id="directory-row-menu" class="ctx-menu vitrine-directory-row-menu" data-directory-row-actions<?= $rowArchive ? ' data-archive' : '' ?> role="menu" aria-label="Действия со строкой" hidden>
  <?php if ($rowRelatedLabel): ?>
  <a class="ctx-item" role="menuitem" data-directory-related hidden><?= $escape($rowRelatedLabel) ?></a>
  <?php endif; ?>
  <?php if ($canManageRows): ?>
  <?php if ($directory === 'employees'): ?>
  <button class="ctx-item" type="button" role="menuitem" data-employee-card>Карточка сотрудника</button>
  <?php endif; ?>
  <button class="ctx-item" type="button" role="menuitem" data-directory-archive-toggle></button>
  <button class="ctx-item is-danger" type="button" role="menuitem" data-directory-delete>Удалить строку</button>
  <?php endif; ?>
</div>
