<?php
declare(strict_types=1);

function workspace_client_manager_values(int $catalogId): array {
    $source = workspace_client_catalog_metadata('catalog_managers_'.$catalogId);
    $local = workspace_client_catalog_metadata('catalog_manager_overrides_'.$catalogId);
    return array_replace($source, $local);
}

function workspace_client_catalog_aliases(int $catalogId): array {
    return workspace_client_catalog_metadata('catalog_client_aliases_'.$catalogId);
}

function workspace_client_remember_alias(int $catalogId, int $entryId, string $normalized): void {
    $key = 'catalog_client_aliases_'.$catalogId;
    $aliases = workspace_client_catalog_metadata($key);
    $aliases[$normalized] = $entryId;
    wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [$key, wjson((object)$aliases)]);
}

function workspace_client_set_manager(int $catalogId, int $entryId, string $manager): void {
    $key = 'catalog_manager_overrides_'.$catalogId;
    $values = workspace_client_catalog_metadata($key);
    $values[$entryId] = $manager;
    wquery('INSERT INTO workspace_meta VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', [$key, wjson((object)$values)]);
}

function workspace_client_catalog_metadata(string $key): array {
    $raw = wquery('SELECT value FROM workspace_meta WHERE `key`=?', [$key])->fetchColumn();
    $values = json_decode((string)$raw, true);
    return is_array($values) ? $values : [];
}

function workspace_client_validate_manager(mixed $value): string {
    if (!is_string($value) || mb_strlen($value) > 160 || preg_match('/[\x00-\x1f]/u', $value)) {
        throw new ApiError('invalid_manager', 'Укажите имя менеджера длиной до 160 символов.');
    }
    return trim($value);
}
