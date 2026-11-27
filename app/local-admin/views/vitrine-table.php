<?php
declare(strict_types=1);
$dragGrip = '<svg aria-hidden="true" class="vitrine-drag-grip" viewBox="0 0 16 16"><circle cx="5" cy="4" r="1.2"/><circle cx="11" cy="4" r="1.2"/><circle cx="5" cy="8" r="1.2"/><circle cx="11" cy="8" r="1.2"/><circle cx="5" cy="12" r="1.2"/><circle cx="11" cy="12" r="1.2"/></svg>';
?>
    <div class="vitrine-grid-region" data-grid-width="<?= $escape($gridWidth) ?>" data-grid-default-width="<?= $escape($defaultGridWidth) ?>">
    <section class="table-card vitrine-table-card" aria-label="<?= $escape($directory ? 'Справочник «'.$currentTable['title'].'»' : $currentTable['title']) ?>">
      <div class="table-scroll vitrine-table-scroll"<?= $canReorderCatalogRows || !empty($canReorderTripRows) ? " data-quick-row-order" : "" ?><?= !empty($showTripRowOrder) ? " data-trip-row-order" : "" ?> tabindex="0" role="region" aria-label="Таблица, прокрутка по горизонтали и вертикали">
        <table class="table vitrine-table<?= !empty($catalogGrid['unloadPoints']) ? ' vitrine-unload-points' : '' ?>" data-freeze-columns<?= $isAvtovyvoz ? ' data-row-page-size="'.WORKSPACE_AVTOVYVOZ_PAGE_SIZE.'"'.(!$rowArchive ? ' data-open-at-last-row' : '') : '' ?><?= $loginArrival ? ' data-center-on-login' : '' ?>>
          <caption class="vitrine-sr-only"><?php if ($directory): ?>Справочник «<?= $escape($currentTable['title']) ?>»<?php else: ?>Последние непустые строки листа <?= $escape($data['sheetTitle'] ?? 'ВЫВОЗ') ?>. Все исходные столбцы.<?php endif; ?></caption>
          <colgroup><col class="vitrine-row-column"><?php foreach ($columns as $column): ?><col class="vitrine-data-column<?= $columnClass($column) ?>" data-column="<?= $escape($column['letter']) ?>"><?php endforeach; ?></colgroup>
          <thead><tr><th scope="col" class="vitrine-row-number">Строка</th>
            <?php foreach ($columns as $column): ?>
              <th scope="col" data-column="<?= $escape($column['letter']) ?>"<?= ($column['export']??true) ? '' : ' data-no-export' ?> class="<?= trim($columnClass($column)) ?>">
                <span class="vitrine-column-letter"><?= $escape($column['letter']) ?><?php if (isset($column['derivedSource'])): ?>
                  <span class="vitrine-derived-icon" role="img" tabindex="0" aria-label="<?= $escape($column['derivedSource']) ?>">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7L12 5"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7L12 19"/></svg>
                  </span><?php endif; ?></span>
                  <div class="display-column-controls">
                  <button type="button" class="display-column-hide" data-column-hide aria-label="<?= $escape('Скрыть столбец '.$column['letter'].' '.$column['label']) ?>" aria-haspopup="dialog">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="m3 3 18 18M10.6 10.7a2 2 0 0 0 2.7 2.7M9.2 5.3a10.8 10.8 0 0 1 2.8-.4c6 0 10 7.1 10 7.1a17.4 17.4 0 0 1-2.7 3.4M6.3 6.3A18.3 18.3 0 0 0 2 12s4 7.1 10 7.1c1.6 0 3-.5 4.3-1.2"/></svg>
                  </button>
                  <button type="button" class="display-column-drag" data-column-drag aria-label="<?= $escape('Переместить столбец '.$column['letter'].' '.$column['label']) ?>" aria-keyshortcuts="ArrowLeft ArrowRight">
                    <?= $dragGrip ?>
                  </button>
                  <button type="button" class="display-column-pin" data-column-pin aria-pressed="false" aria-label="<?= $escape('Закрепление столбца '.$column['letter'].' '.$column['label']) ?>">
                    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17v5m-7-5v-1a2 2 0 0 1 1.1-1.8l1.8-.9A2 2 0 0 0 9 11.5V6H8a2 2 0 0 1 0-4h8a2 2 0 0 1 0 4h-1v5.5a2 2 0 0 0 1.1 1.8l1.8.9A2 2 0 0 1 19 16v1z"/></svg>
                  </button>
                  </div>
                <span class="vitrine-column-label"><?= $escape($column['label']) ?></span>
              </th>
            <?php endforeach; ?>
          </tr></thead>
          <tbody id="vitrine-rows">
          <?php require __DIR__.'/vitrine-rows.php'; ?>
          </tbody>
          <?php if ($showDraftRows || $tripDraft) require __DIR__.'/vitrine-drafts.php'; ?>
        </table>
        <?php if ($canReorderCatalogRows || !empty($canReorderTripRows)): ?>
        <template id="vitrine-row-drag-template">
          <button type="button" class="vitrine-cell-icon" draggable="true" data-quick-row-drag aria-keyshortcuts="ArrowUp ArrowDown"><?= $dragGrip ?></button>
        </template>
        <?php endif; ?>
        <div class="vitrine-message vitrine-empty" id="vitrine-empty" <?= count($rows) || $tripDraft || ($showDraftRows && $catalogGrid['search'] === '') ? 'hidden' : '' ?> role="status"><?= ($catalogGrid ? $catalogGrid['search'] !== '' : count($rows) > 0) ? 'По вашему запросу ничего не найдено.' : 'В таблице пока нет строк для отображения.' ?></div>
      </div>
    </section>
    </div>
