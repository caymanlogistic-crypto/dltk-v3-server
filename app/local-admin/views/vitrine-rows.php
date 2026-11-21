          <?php foreach ($rows as $row): ?>
            <tr data-vitrine-row data-row-number="<?= (int)$row['rowIndex'] ?>"><th scope="row" class="vitrine-row-number cell-mono"><?= $escape($row['rowIndex']) ?></th>
            <?php foreach ($columns as $column): ?>
            <?php
              $letter = $column['letter'];
              $value = $row['values'][$letter] ?? '';
              $derivedSource = $column['derivedSource'] ?? null;
              $emptyLabel = $column['derivedEmptyLabel'] ?? '';
              if (is_bool($value)) $value = $value ? 'TRUE' : 'FALSE';
              $original = $row['originalValues'][$letter] ?? $value;
              if (is_bool($original)) $original = $original ? 'TRUE' : 'FALSE';
              $edit = $row['edits'][$letter] ?? [];
              $edited = !empty($edit['active']);
              $conflict = !empty($edit['conflict']);
              $hint = ($conflict ? 'Проверьте актуальное значение. ' : ($edited ? 'Изменено в БД. ' : '')) . 'Щелчок — изменить. ' . (string)$value;
              if (($row['kinds'][$letter] ?? '') === 'time') $hint = 'Наберите 800 или 0800 → 08:00. Enter — сохранить, Esc — отменить, F2 — редактор. ' . (string)$value;
              if ($derivedSource !== null) $hint = $derivedSource . '. ' . ($value === '' ? $emptyLabel . '.' : $value);
              $statusColor = $letter === 'A' ? (string)($row['statusColor'] ?? '') : '';
              $hasStatusColor = preg_match('/^#[0-9a-fA-F]{6}$/D', $statusColor) === 1;
              $statusId = $letter === 'A' ? ($row['statusId'] ?? '') : '';
              $choices = json_encode($row['choices'][$letter] ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
            ?>
            <td data-edit-cell<?php if ($derivedSource !== null): ?> aria-readonly="true" data-derived-source="<?= $escape($derivedSource) ?>" data-empty-label="<?= $escape($emptyLabel) ?>"<?php if ($value === ''): ?> aria-label="<?= $escape($emptyLabel) ?>"<?php endif; ?><?php endif; ?> data-reference-id="<?= (int)($row['references'][$letter]['id']??0) ?>" data-catalog-id="<?= (int)($row['catalogBindings'][$letter]??0) ?>" data-kind="<?= $escape($row['kinds'][$letter] ?? 'text') ?>" data-row="<?= $escape($row['rowIndex']) ?>" data-column="<?= $escape($letter) ?>" data-label="<?= $escape($column['label']) ?>" data-revision="<?= (int)($edit['revision'] ?? 0) ?>" data-base="<?= $escape($row['baseHash'] ?? '') ?>" data-original="<?= $escape($original) ?>" data-choices="<?= $escape($choices) ?>" data-status-id="<?= $escape($statusId) ?>" data-status-color="<?= $escape($hasStatusColor ? $statusColor : '') ?>" class="<?= trim($columnClass($column)) ?> <?= $edited ? 'local-edited' : '' ?><?= $conflict ? ' conflict' : '' ?><?= $hasStatusColor ? ' has-status-color' : '' ?>"<?= $hasStatusColor ? ' style="--status-fill: ' . $escape($statusColor) . '"' : '' ?> tabindex="-1" title="<?= $escape($hint) ?>"><?= $escape($value) ?></td>
            <?php endforeach; ?></tr>
          <?php endforeach; ?>
