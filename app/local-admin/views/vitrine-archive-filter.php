<?php
declare(strict_types=1);
?>
<div class="vitrine-directory-visibility"<?= ($directory ?? null) === 'employees' ? ' data-employee-visibility' : '' ?>
     data-directory-visibility="<?= $rowArchive ? '1' : (!empty($catalogGrid['activeOnly']) ? 'active' : (!empty($catalogGrid['showAll']) ? 'all' : '')) ?>"
     data-filter-label="<?= $escape($visibilityAria ?? 'Фильтр записей') ?>"<?php if (!empty($visibilityLabel)): ?>
     data-all-label="<?= $escape($visibilityLabel) ?>"<?php endif; ?>></div>
