<?php $compactTripRows = ($data['table']['slug'] ?? null)==='vyvoz' && empty($catalogGrid); ?>
          <?php foreach ($rows as $row): ?>
            <tr data-vitrine-row
                <?= !empty($row['archived']) ? 'data-directory-archived' : '' ?>
                <?php if (isset($row['actionLabel'])): ?>data-directory-action-label="<?= $escape($row['actionLabel']) ?>"<?php endif; ?>
                <?php if (isset($row['rowToken'])): ?>data-row-token="<?= $escape($row['rowToken']) ?>"<?php endif; ?>
                <?php if (isset($row['dropoffDetails'])): ?>data-dropoff-details="<?= $escape(wjson($row['dropoffDetails'])) ?>"<?php endif; ?>
                <?php if (!empty($row['fieldColorStatuses'])): ?>data-field-color-statuses="<?= $escape(wjson($row['fieldColorStatuses'])) ?>"<?php endif; ?>
                <?php if (!empty($row['relatedUrl'])): ?>data-related-url="<?= $escape(function_exists('app_url_path') ? app_url_path($row['relatedUrl']) : $row['relatedUrl']) ?>"<?php endif; ?>
                <?= !empty($row['draftSlot']) ? 'data-draft-slot' : '' ?>
                <?= !empty($row['tripDraft']) ? 'data-trip-draft' : '' ?>
                <?php if (!empty($row['tripDate'])): ?>data-trip-date="<?= $escape($row['tripDate']) ?>"<?php endif; ?>
                data-row-number="<?= (int)$row['rowIndex'] ?>"><th scope="row" class="vitrine-row-number cell-mono"><?= $escape($row['rowLabel'] ?? $row['rowIndex']) ?><?php if (!empty($row['rowActions'])): ?><button type="button" class="vitrine-row-menu-trigger" data-directory-row-menu aria-label="Действия со строкой <?= $escape($row['rowLabel']) ?>" aria-haspopup="menu" aria-expanded="false" aria-controls="directory-row-menu" tabindex="-1">⋯</button><?php endif; ?></th>
            <?php foreach ($columns as $column): ?>
            <?php
              $letter = $column['letter'];
              $kind = $row['kinds'][$letter] ?? $column['kind'] ?? 'text';
              $compactInput = $row['compactInputs'][$letter] ?? $column['compactInput'] ?? null;
              $opensCard = in_array($kind,['unload-contacts','client-unload-points','client-managers'],true);
              $tripDriverCard = !empty($column['tripDriverCard']) || !empty($row['tripDriverCards'][$letter]);
              $orderStatus = $row['orderStatuses'][$letter] ?? ($letter==='B' && !isset($catalogGrid) ? ($row['orderStatus'] ?? null) : null);
              $orderFill = $letter==='B' ? ($row['orderFill'] ?? ($orderStatus ? workspace_client_order_status($orderStatus)['color'] : '')) : '';
              $orderFill = $row['fieldColorFills'][$letter] ?? $orderFill;
              $cellStyle = $orderFill ? '--order-status-fill: '.$orderFill : '';
              $catalogFill = $row['catalogFills'][$letter] ?? '';
              if (preg_match('/^#[0-9a-fA-F]{6}$/D', $catalogFill)) $cellStyle = '--catalog-fill: '.$catalogFill;
              $catalogReadOnly = !empty($row['archived']) || (isset($catalogGrid) && ($catalogGrid['archive'] || !$catalogGrid['canEdit']));
              $value = $row['values'][$letter] ?? '';
              $derivedSource = $row['derivedSources'][$letter] ?? $column['derivedSource'] ?? null;
              $emptyLabel = $column['derivedEmptyLabel'] ?? '';
              if (is_bool($value)) $value = $value ? 'TRUE' : 'FALSE';
              $tripPersonName = $compactTripRows && in_array($letter,['AL','AM'],true);
              $displayValue = $tripPersonName ? workspace_account_short_names((string)$value) : $value;
              $original = $row['originalValues'][$letter] ?? $value;
              if (is_bool($original)) $original = $original ? 'TRUE' : 'FALSE';
              $edit = $row['edits'][$letter] ?? [];
              $edited = !empty($edit['active']);
              $conflict = !empty($edit['conflict']);
              $readOnlyMessage = $column['readOnly'] ?? $row['readOnly'][$letter] ?? (!empty($row['archived']) ? 'Запись в архиве.' : ($catalogReadOnly ? 'Запись доступна только для просмотра.' : ''));
              $requiredChoice = !empty($row['requiredChoices'][$letter]);
              $statusColor = $letter === 'A' ? (string)($row['statusColor'] ?? '') : '';
              $hasStatusColor = preg_match('/^#[0-9a-fA-F]{6}$/D', $statusColor) === 1;
              if ($hasStatusColor) $cellStyle = '--status-fill: '.$statusColor;
              $statusId = $letter === 'A' ? ($row['statusId'] ?? '') : '';
              $sharedChoice = $row['sharedChoices'][$letter] ?? null;
              $choices = json_encode($sharedChoice ? [] : ($row['choices'][$letter] ?? []), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
            ?>
            <td data-edit-cell<?php if ($tripPersonName): ?> data-trip-person-name="<?= $escape($value) ?>"<?php endif; ?><?php if (($compactTripRows || !empty($row['tripDraft'])) && workspace_trip_supports_formula('vyvoz',$letter)): ?> data-formula-input<?php if (isset($row['tripFormulas'][$letter])): ?> data-formula="<?= $escape($row['tripFormulas'][$letter]) ?>"<?php endif; ?><?php endif; ?><?php if (isset($column['derivedFrom'])): ?> data-derived-from="<?= $escape($column['derivedFrom']) ?>"<?php endif; ?><?php if ($tripDriverCard): ?> data-trip-driver-cell<?php endif; ?><?php if (!empty($column['fleetDocumentsVisible'])): ?> data-fleet-documents-visible<?php endif; ?><?php if ($opensCard && empty($row['draftSlot'])): ?> aria-haspopup="dialog"<?php endif; ?><?php if ($compactInput!==null): ?> data-compact-input="<?= $escape($compactInput) ?>"<?php endif; ?><?php if (($row['kinds'][$letter] ?? '') === 'slot' && $value === 'Слот?'): ?> data-slot-request<?php endif; ?><?php if (isset($row['draftTypes'][$letter])): ?> data-draft-type="<?= $escape($row['draftTypes'][$letter]) ?>"<?php endif; ?><?php if (!empty($row['singleLine'][$letter])): ?> data-single-line<?php endif; ?><?php if ($orderStatus !== null): ?> data-order-status="<?= $escape($orderStatus) ?>"<?php endif; ?><?php if ($requiredChoice): ?> data-required-choice<?php endif; ?><?php if ($readOnlyMessage !== ''): ?> aria-readonly="true" data-readonly-cell="<?= $escape($readOnlyMessage) ?>"<?php endif; ?><?php if ($derivedSource !== null): ?> aria-readonly="true" data-derived-source="<?= $escape($derivedSource) ?>" data-empty-label="<?= $escape($emptyLabel) ?>"<?php if ($value === ''): ?> aria-label="<?= $escape($emptyLabel) ?>"<?php endif; ?><?php endif; ?><?php if (!$compactTripRows || !empty($row['references'][$letter]['id'])): ?> data-reference-id="<?= (int)($row['references'][$letter]['id']??0) ?>"<?php endif; ?> data-catalog-id="<?= (int)($row['catalogBindings'][$letter]??0) ?>" data-kind="<?= $escape($kind) ?>" data-row="<?= $escape($row['rowIndex']) ?>" data-column="<?= $escape($letter) ?>" data-label="<?= $escape($column['label']) ?>" data-revision="<?= (int)($edit['revision'] ?? 0) ?>" data-base="<?= $escape($edit['baseHash'] ?? $row['baseHash'] ?? '') ?>" data-original="<?= $escape($original) ?>"<?php if ($sharedChoice): ?> data-shared-choices="<?= $escape($sharedChoice) ?>"<?php endif; ?><?php if (!$compactTripRows || (!$sharedChoice && $choices!=='[]')): ?> data-choices="<?= $escape($choices) ?>"<?php endif; ?><?php if (!$compactTripRows || $letter==='A'): ?> data-status-id="<?= $escape($statusId) ?>" data-status-color="<?= $escape($hasStatusColor ? $statusColor : '') ?>"<?php endif; ?> class="<?= trim($columnClass($column)) ?> <?= $edited ? 'local-edited' : '' ?><?= $conflict ? ' conflict' : '' ?><?= $hasStatusColor ? ' has-status-color' : '' ?>"<?= $cellStyle !== '' ? ' style="' . $escape($cellStyle) . '"' : '' ?> tabindex="-1"><?php if ($tripDriverCard): ?><div class="vitrine-trip-vehicle"><span data-trip-vehicle-value><?= $escape($value) ?></span><button class="vitrine-cell-icon" type="button" data-trip-driver-button tabindex="-1" aria-haspopup="dialog" aria-label="Карточка водителя" aria-keyshortcuts="Alt+Enter"<?= $value === '' ? ' hidden' : '' ?>><svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 17V6h12v11M14 9h4l4 4v4h-2M2 17h2m4 0h8"/><circle cx="6" cy="17" r="2"/><circle cx="18" cy="17" r="2"/></svg></button></div><?php else: ?><?= $escape($displayValue) ?><?php endif; ?></td>
            <?php endforeach; ?>
            <?php if (!empty($row['draftSlot']) && empty($row['tripDraft'])): ?><td class="vitrine-draft-note" hidden role="status">Черновик. Не добавлено в справочник</td><?php endif; ?>
            </tr>
          <?php endforeach; ?>
