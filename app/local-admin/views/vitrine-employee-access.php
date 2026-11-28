<?php
declare(strict_types=1);
$access = $row['employeeAccess'];
$tag = $access['editable'] ? 'button' : 'span';
$state = $access['paused'] ? 'paused' : 'active';
$label = $access['paused'] ? 'Доступ приостановлен' : 'Доступ активен';
if ($access['editable']) $label .= $access['paused'] ? '. Возобновить доступ' : '. Приостановить доступ';
?>
<span data-employee-name-value><?= $escape($displayValue) ?></span><<?= $tag ?> class="vitrine-cell-icon" data-employee-access-indicator="<?= $state ?>" aria-label="<?= $escape($label) ?>"<?= $access['editable'] ? ' type="button" aria-keyshortcuts="Alt+Enter"' : ' role="img"' ?>><svg viewBox="0 0 24 24" aria-hidden="true">
    <path class="employee-access-play" d="m8 5 11 7-11 7Z"/>
    <path class="employee-access-pause" d="M9 5v14m6-14v14"/>
</svg></<?= $tag ?>>
