<?php
declare(strict_types=1);
?>
<button class="btn btn-secondary btn-icon vitrine-excel-button" type="button" data-vitrine-export="<?= $escape($currentTable['title']) ?>"
        aria-label="Скачать Excel" title="Скачать Excel (.xlsx)">
  <svg aria-hidden="true" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    <path d="M12 3v12m-4-4 4 4 4-4M4 16v4h16v-4"/>
  </svg>
</button>
