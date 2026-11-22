<?php
declare(strict_types=1);

function workspace_merge_preferences(array $current, array $changes): array {
    $keys = ['order', 'hidden', 'widths', 'preset', 'lines', 'layoutWidth'];
    if (array_diff(array_keys($changes), $keys)) throw new ApiError('invalid_preferences', 'Неизвестный параметр отображения.');
    foreach ($changes as $key => $value) {
        if ($key !== 'widths') {
            $current[$key] = $value;
            continue;
        }
        if (!is_array($value)) throw new ApiError('invalid_preferences', 'Некорректные размеры столбцов.');
        $current['widths'] ??= [];
        foreach ($value as $column => $width) {
            if ($width === null) unset($current['widths'][$column]);
            else $current['widths'][$column] = $width;
        }
    }
    return $current;
}

function workspace_read_preferences(array $grid, int $userId): array {
    if (isset($grid['catalogId']) || isset($grid['directoryKey'])) {
        $key = workspace_catalog_preferences_key($grid, $userId);
        $value = wquery('SELECT value FROM workspace_meta WHERE `key`=?', [$key])->fetchColumn();
        return $value === false ? ['settings'=>null, 'version'=>0] : json_decode($value, true);
    }
    $row = wquery('SELECT settings,version FROM user_view_preferences WHERE user_id=? AND table_id=?', [$userId, $grid['id']])->fetch();
    return $row ? ['settings'=>json_decode($row['settings'], true), 'version'=>(int)$row['version']] : ['settings'=>null, 'version'=>0];
}

function workspace_catalog_preferences_key(array $grid, int $userId): string {
    if (isset($grid['directoryKey'])) return 'directory_preferences_'.$userId.'_'.$grid['directoryKey'];
    return 'catalog_preferences_'.$userId.'_'.$grid['catalogId'];
}

function workspace_prune_preference_columns(array $settings,array $columns): array {
    foreach (['order','hidden'] as $key) {
        if (isset($settings[$key]) && is_array($settings[$key])) {
            $settings[$key]=array_values(array_filter($settings[$key],
                static fn($column): bool=>in_array($column,$columns,true)));
        }
    }
    if (isset($settings['widths']) && is_array($settings['widths'])) {
        $settings['widths']=array_intersect_key($settings['widths'],array_fill_keys($columns,true));
    }
    return $settings;
}

function workspace_validate_preferences(mixed $settings, array $columns): void {
    if (!is_array($settings) || strlen(wjson($settings)) > 40000) throw new ApiError('invalid_preferences', 'Некорректные настройки отображения.');
    foreach (['order', 'hidden'] as $key) {
        if (!isset($settings[$key])) continue;
        if (!is_array($settings[$key])) throw new ApiError('invalid_preferences', 'Некорректный список столбцов.');
        foreach ($settings[$key] as $column) {
            if (!in_array($column, $columns, true)) throw new ApiError('invalid_preferences', 'В настройках есть неизвестные столбцы.');
        }
    }
    $choices = ['preset'=>['base','saturated','acid'], 'lines'=>['base','dark','black'], 'layoutWidth'=>['full','compact']];
    foreach ($choices as $key => $values) {
        if (isset($settings[$key]) && !in_array($settings[$key], $values, true)) {
            throw new ApiError('invalid_preferences', 'Неизвестный вариант отображения.');
        }
    }
    $widths = $settings['widths'] ?? [];
    if (!is_array($widths)) throw new ApiError('invalid_preferences', 'Некорректные размеры столбцов.');
    foreach ($widths as $column => $width) {
        if (!in_array($column, $columns, true) || !is_numeric($width) || $width < 44 || $width > 1200) {
            throw new ApiError('invalid_preferences', 'Допустимая ширина 44–1200 пикселей.');
        }
    }
}

function workspace_preferences(array $grid, ?array $body = null): array {
    $userId = (int)workspace_current_user()['id'];
    if ($body === null) return workspace_read_preferences($grid, $userId);
    $patch = $body['changes'] ?? null;
    if ($patch !== null && !is_array($patch)) throw new ApiError('invalid_preferences', 'Некорректные изменения настроек.');
    $columns = array_column($grid['columns'], 'letter');
    $defaults = ['order'=>$columns, 'hidden'=>[], 'widths'=>[], 'preset'=>'base', 'lines'=>'base', 'layoutWidth'=>$grid['defaultGridWidth'] ?? 'full'];
    workspace_begin();
    try {
        $previous = workspace_read_preferences($grid, $userId);
        $version = $previous['version'];
        if ($patch === null && isset($body['expectedVersion']) && (int)$body['expectedVersion'] !== $version) {
            throw new ApiError('preference_conflict', 'Настройки изменены в другом окне. Повторите изменение.', 409);
        }
        $prior=workspace_prune_preference_columns($previous['settings']??$defaults,$columns);
        $settings = $patch === null ? ($body['settings'] ?? null) : workspace_merge_preferences($prior,$patch);
        workspace_validate_preferences($settings, $columns);
        $result = ['settings'=>$settings, 'version'=>$version+1];
        if (isset($grid['catalogId']) || isset($grid['directoryKey'])) {
            wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [workspace_catalog_preferences_key($grid, $userId), wjson($result)]);
        } else {
            wquery('INSERT INTO user_view_preferences(user_id,table_id,settings,version) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE settings=VALUES(settings),version=VALUES(version)', [$userId, $grid['id'], wjson($settings), $result['version']]);
        }
        workspace_event('preferences.changed', ['userId'=>$userId, 'tableSlug'=>$grid['slug']] + $result);
        workspace_db()->commit();
        return $result;
    } catch (Throwable $error) {
        if (workspace_db()->inTransaction()) workspace_db()->rollBack();
        throw $error;
    }
}
