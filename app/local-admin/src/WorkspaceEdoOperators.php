<?php
declare(strict_types=1);

function workspace_edo_operator_code(mixed $value): string {
    $code=is_string($value) ? strtoupper(trim($value)) : '';
    if (!preg_match('/^2[A-Z0-9]{2}$/D',$code)) {
        throw new ApiError('invalid_edo_operator_code','Укажите код из трёх символов, например 2BM.');
    }
    return $code;
}

function workspace_edo_operator_values(array $entry): array {
    return ['A'=>strtoupper($entry['normalized']),'B'=>$entry['name']];
}

function workspace_edo_operator_names(): array {
    static $names=null;
    return $names??=wquery("SELECT e.normalized,e.name FROM catalog_entries e
        JOIN catalogs c ON c.id=e.catalog_id WHERE c.kind='edo-operators' AND e.active=TRUE"
    )->fetchAll(PDO::FETCH_KEY_PAIR);
}

function workspace_edo_operator_assert_unused(string $code): void {
    $used=(int)wquery("SELECT COUNT(*) FROM catalog_entries e JOIN catalogs c ON c.id=e.catalog_id
        JOIN workspace_meta m ON m.`key`=CONCAT('carrier_details_',e.id)
        WHERE c.kind='carriers' AND NOT EXISTS (
            SELECT 1 FROM workspace_meta d WHERE d.`key`=CONCAT('catalog_deleted_client_',e.id))
        AND UPPER(LEFT(JSON_UNQUOTE(JSON_EXTRACT(m.value,'$.edoId')),3))=?", [strtoupper($code)])->fetchColumn();
    if ($used) throw new ApiError('entry_in_use','Код используется в справочнике «Перевозчики». Связанных записей — '.$used.'.',409);
}
