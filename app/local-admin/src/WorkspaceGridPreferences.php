<?php
declare(strict_types=1);

function workspace_merge_preferences(array $current, array $changes): array {
    $keys = ['order', 'hidden', 'pinned', 'widths', 'preset', 'lines', 'layoutWidth'];
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
        $preferences=$value === false ? ['settings'=>null, 'version'=>0] : json_decode($value, true);
        $orderVersion=$grid['columnOrderVersion']??null;
        if ($orderVersion!==null && ($preferences['columnOrderVersion']??null)!==$orderVersion) {
            if (is_array($preferences['settings'])) {
                unset($preferences['settings']['order']);
            }
            $preferences['columnOrderVersion']=$orderVersion;
        }
        return $preferences;
    }
    $row = wquery('SELECT settings,version FROM user_view_preferences WHERE user_id=? AND table_id=?', [$userId, $grid['id']])->fetch();
    return $row ? ['settings'=>json_decode($row['settings'], true), 'version'=>(int)$row['version']] : ['settings'=>null, 'version'=>0];
}

function workspace_catalog_preferences_key(array $grid, int $userId): string {
    if (isset($grid['directoryKey'])) return 'directory_preferences_'.$userId.'_'.$grid['directoryKey'];
    return 'catalog_preferences_'.$userId.'_'.$grid['catalogId'];
}

function workspace_width_defaults_key(array $grid): string {
    if (isset($grid['directoryKey'])) return 'directory_width_defaults_'.$grid['directoryKey'];
    if (isset($grid['catalogId'])) return 'catalog_width_defaults_'.$grid['catalogId'];
    return 'table_width_defaults_'.$grid['id'];
}

function workspace_read_width_defaults(array $grid): array {
    $key=workspace_width_defaults_key($grid);
    $saved=wquery('SELECT value FROM workspace_meta WHERE `key`=?',[$key])->fetchColumn();
    if ($saved!==false) return json_decode($saved,true);

    return ['widths'=>[],'version'=>0];
}

function workspace_layout_baseline(array $grid): array {
    return ['order'=>array_column($grid['columns'],'letter'), 'hidden'=>[], 'pinned'=>[],
        'widths'=>[], 'lines'=>'base', 'layoutWidth'=>$grid['defaultGridWidth']??'full'];
}

function workspace_layout_overrides(array $grid, array $preferences): array {
    $baseline=workspace_layout_baseline($grid);
    $columns=$baseline['order'];
    $settings=workspace_prune_preference_columns(
        array_intersect_key($preferences['settings']??[], $baseline),$columns);
    if (empty($preferences['settings']['_layoutOverrides'])) {
        // Old writers stored a full baseline even when the user only changed one setting.
        foreach ($baseline as $key=>$value) {
            if (($settings[$key]??null)===$value) unset($settings[$key]);
        }
    }
    return $settings;
}

function workspace_read_layout_defaults(array $grid, string $key): array {
    $saved=wquery('SELECT value FROM workspace_meta WHERE `key`=?',[$key])->fetchColumn();
    $orderVersion=$grid['columnOrderVersion']??null;
    $columns=array_column($grid['columns'],'letter');
    $baseline=isset($grid['defaultHiddenColumns'])
        ? ['hidden'=>array_values(array_intersect($grid['defaultHiddenColumns'],$columns))]
        : [];
    if ($saved!==false) {
        $defaults=json_decode($saved,true);
        if (($defaults['columnOrderVersion']??null)!==$orderVersion) unset($defaults['settings']['order']);
        $defaults['settings']=workspace_prune_preference_columns($defaults['settings'],$columns)+$baseline;
        return $defaults;
    }

    $admins=wquery("SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id=u.id
        JOIN roles r ON r.id=ur.role_id WHERE u.active=TRUE AND r.code='superadmin' ORDER BY u.id")->fetchAll(PDO::FETCH_COLUMN);
    $settings=[];
    foreach ($admins as $adminId) {
        $settings=workspace_layout_overrides($grid,workspace_read_preferences($grid,(int)$adminId));
        if ($settings) break;
    }
    $widths=workspace_read_width_defaults($grid);
    if ($widths['version'] || $widths['widths']) $settings['widths']=$widths['widths'];
    return ['settings'=>workspace_prune_preference_columns($settings,$columns)+$baseline,
        'version'=>0,'columnOrderVersion'=>$orderVersion];
}

function workspace_effective_preferences(array $grid, array $personal, array $defaults, bool $editsDefaults): array {
    $overrides=$editsDefaults ? [] : workspace_layout_overrides($grid,$personal);
    $settings=$overrides+$defaults['settings']+workspace_layout_baseline($grid);
    $settings['widths']=$overrides['widths']??[];
    return ['settings'=>$settings,'layoutOverrides'=>$overrides,'layoutDefaults'=>$defaults,
        'version'=>$personal['version'],'columnOrderVersion'=>$grid['columnOrderVersion']??null,
        'editsLayoutDefaults'=>$editsDefaults];
}

function workspace_read_color_preset(int $userId): ?array {
    $stored=wquery('SELECT value FROM workspace_meta WHERE `key`=?',['grid_color_preset_'.$userId])->fetchColumn();
    if ($stored!==false) return json_decode($stored,true);

    $legacy=wquery("SELECT JSON_UNQUOTE(JSON_EXTRACT(p.settings,'$.preset'))
        FROM user_view_preferences p JOIN table_definitions t ON t.id=p.table_id
        WHERE p.user_id=? AND t.slug='vyvoz'",[$userId])->fetchColumn();
    return in_array($legacy,['base','saturated','acid','night'],true) ? ['value'=>$legacy,'version'=>0] : null;
}

function workspace_read_default_color_preset(): ?array {
    $stored=wquery('SELECT value FROM workspace_meta WHERE `key`=?',['grid_color_preset_default'])->fetchColumn();
    return $stored===false ? null : json_decode($stored,true);
}

function workspace_prune_preference_columns(array $settings,array $columns): array {
    foreach (['order','hidden','pinned'] as $key) {
        if (isset($settings[$key]) && is_array($settings[$key])) {
            $settings[$key]=array_values(array_filter($settings[$key],
                static fn($column): bool=>in_array($column,$columns,true)));
        }
    }
    if (in_array('U2',$columns,true) && !empty($settings['order']) && !in_array('U2',$settings['order'],true)) {
        $prior=array_search('U',$settings['order'],true);
        $next=array_search('V',$settings['order'],true);
        if ($prior!==false || $next!==false) {
            array_splice($settings['order'],$prior!==false ? $prior+1 : $next,0,['U2']);
        }
    }
    if (isset($settings['widths']) && is_array($settings['widths'])) {
        $settings['widths']=array_intersect_key($settings['widths'],array_fill_keys($columns,true));
    }
    return $settings;
}

function workspace_validate_preferences(mixed $settings, array $columns): void {
    if (!is_array($settings) || strlen(wjson($settings)) > 40000) throw new ApiError('invalid_preferences', 'Некорректные настройки отображения.');
    foreach (['order', 'hidden', 'pinned'] as $key) {
        if (!isset($settings[$key]) && ($key!=='pinned' || !array_key_exists($key,$settings))) continue;
        if (!is_array($settings[$key])) throw new ApiError('invalid_preferences', 'Некорректный список столбцов.');
        foreach ($settings[$key] as $column) {
            if (!in_array($column, $columns, true)) throw new ApiError('invalid_preferences', 'В настройках есть неизвестные столбцы.');
        }
    }
    if (isset($settings['pinned']) && (!array_is_list($settings['pinned']) || count(array_unique($settings['pinned']))!==count($settings['pinned']))) {
        throw new ApiError('invalid_preferences', 'Закреплённые столбцы должны быть указаны в порядке закрепления без повторов.');
    }
    $choices = ['preset'=>['base','saturated','acid','night'], 'lines'=>['base','dark','black'], 'layoutWidth'=>['full','compact']];
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

function workspace_edits_layout_defaults(int $userId): bool {
    return (bool)wquery("SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id
        WHERE ur.user_id=? AND r.code='superadmin'",[$userId])->fetchColumn();
}

function workspace_preferences(array $grid, ?array $body = null): array {
    $userId = (int)workspace_current_user()['id'];
    $editsDefaults=workspace_edits_layout_defaults($userId);
    $layoutKey=str_replace('_width_defaults_', '_layout_defaults_',workspace_width_defaults_key($grid));
    if ($body === null) {
        return workspace_effective_preferences($grid,workspace_read_preferences($grid,$userId),
            workspace_read_layout_defaults($grid,$layoutKey),$editsDefaults)+
            ['colorPreset'=>$editsDefaults ? null : workspace_read_color_preset($userId),
                'defaultColorPreset'=>workspace_read_default_color_preset(),'layoutDefaultsKey'=>$layoutKey];
    }
    $patch = $body['changes'] ?? null;
    if ($patch !== null && !is_array($patch)) throw new ApiError('invalid_preferences', 'Некорректные изменения настроек.');
    $columns = array_column($grid['columns'], 'letter');
    $orderVersion=$grid['columnOrderVersion']??null;
    $staleOrder=$orderVersion!==null && ($body['columnOrderVersion']??null)!==$orderVersion;
    workspace_begin();
    try {
        $previous=workspace_read_preferences($grid,$userId);
        $defaults=workspace_read_layout_defaults($grid,$layoutKey);
        $version=$previous['version'];
        if ($patch === null && isset($body['expectedVersion']) && (int)$body['expectedVersion'] !== $version) {
            throw new ApiError('preference_conflict', 'Настройки изменены в другом окне. Повторите изменение.', 409);
        }
        $prior=$editsDefaults ? $defaults['settings'] : workspace_layout_overrides($grid,$previous);
        $submitted=$patch ?? ($body['settings']??null);
        if (!is_array($submitted)) throw new ApiError('invalid_preferences','Некорректные настройки отображения.');
        if ($staleOrder) unset($submitted['order']);
        $settings=workspace_merge_preferences($patch!==null ? $prior : [],$submitted);
        if ($patch===null) {
            foreach (['order','pinned',...($editsDefaults && isset($grid['defaultHiddenColumns']) ? ['hidden'] : [])] as $key) {
                if (!array_key_exists($key,$settings) && array_key_exists($key,$prior)) $settings[$key]=$prior[$key];
            }
        }
        workspace_validate_preferences($settings,$columns);
        $layout=array_intersect_key($settings,workspace_layout_baseline($grid));
        if ($editsDefaults && $layout!==$defaults['settings']) {
            $defaults=['settings'=>$layout,'version'=>$defaults['version']+1,'columnOrderVersion'=>$orderVersion];
            wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',[$layoutKey,wjson($defaults)]);
            workspace_event('grid.layout.changed',['layoutDefaultsKey'=>$layoutKey,'layoutDefaults'=>$defaults]);
        }
        $settings=($editsDefaults ? [] : $layout)+['_layoutOverrides'=>true];
        $colorPreset=$editsDefaults ? null : workspace_read_color_preset($userId);
        $defaultColorPreset=workspace_read_default_color_preset();
        if (array_key_exists('preset',$submitted)) {
            $chosen=$submitted['preset'];
            if (!in_array($chosen,['base','saturated','acid','night'],true)) {
                throw new ApiError('invalid_preferences','Неизвестный цветовой пресет.');
            }
            if ($editsDefaults) {
                $defaultColorPreset=['value'=>$chosen,'version'=>($defaultColorPreset['version']??0)+1];
                wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
                    ['grid_color_preset_default',wjson($defaultColorPreset)]);
                workspace_event('grid.color_default.changed',['defaultColorPreset'=>$defaultColorPreset]);
            } else {
                $colorPreset=['value'=>$chosen,'version'=>($colorPreset['version']??0)+1];
                wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)',
                    ['grid_color_preset_'.$userId,wjson($colorPreset)]);
            }
        }
        if (($colorPreset['version']??null)===0 && isset($previous['settings']['preset'])) {
            $settings['preset']=$previous['settings']['preset'];
        }
        $result = ['settings'=>$settings, 'version'=>$version+1];
        if ($orderVersion!==null) $result['columnOrderVersion']=$orderVersion;
        if (isset($grid['catalogId']) || isset($grid['directoryKey'])) {
            wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [workspace_catalog_preferences_key($grid, $userId), wjson($result)]);
        } else {
            wquery('INSERT INTO user_view_preferences(user_id,table_id,settings,version) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE settings=VALUES(settings),version=VALUES(version)', [$userId, $grid['id'], wjson($settings), $result['version']]);
        }
        $result=workspace_effective_preferences($grid,$result,$defaults,$editsDefaults)+
            ['layoutDefaultsKey'=>$layoutKey,'colorPreset'=>$colorPreset,'defaultColorPreset'=>$defaultColorPreset];
        workspace_event('preferences.changed', ['userId'=>$userId, 'tableSlug'=>$grid['slug']] + $result);
        workspace_db()->commit();
        return $result;
    } catch (Throwable $error) {
        if (workspace_db()->inTransaction()) workspace_db()->rollBack();
        throw $error;
    }
}
