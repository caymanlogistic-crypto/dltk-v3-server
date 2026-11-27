START TRANSACTION;
SELECT value FROM workspace_meta WHERE `key`='marker' FOR UPDATE;

SET @etrn_table = (SELECT id FROM table_definitions WHERE slug='vyvoz' AND is_demo=FALSE);
SET @etrn_columns = (SELECT columns_json FROM table_definitions WHERE id=@etrn_table);
SET @etrn_after_u = JSON_UNQUOTE(JSON_SEARCH(@etrn_columns,'one','U',NULL,'$[*].letter'));
SET @etrn_can_seed = @etrn_after_u IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM catalog_bindings b JOIN catalogs c ON c.id=b.catalog_id
    WHERE b.table_id=@etrn_table AND b.field_key='U2' AND c.kind<>'etrn-signing-statuses'
);

SET @etrn_insert_column = @etrn_can_seed AND JSON_SEARCH(@etrn_columns,'one','U2',NULL,'$[*].letter') IS NULL;
SET @etrn_u2_position = CAST(SUBSTRING_INDEX(SUBSTRING(@etrn_after_u,3),']',1) AS UNSIGNED) + 1;
UPDATE table_definitions
SET columns_json=JSON_ARRAY_INSERT(columns_json,CONCAT('$[',@etrn_u2_position,']'),
    JSON_OBJECT('letter','U2','label','Статус ЭТРН'))
WHERE id=@etrn_table AND @etrn_insert_column;

SET @etrn_catalog = COALESCE(
    (SELECT MIN(b.catalog_id) FROM catalog_bindings b JOIN catalogs c ON c.id=b.catalog_id
        WHERE b.table_id=@etrn_table AND b.field_key='U2' AND c.kind='etrn-signing-statuses'),
    (SELECT MIN(id) FROM catalogs WHERE kind='etrn-signing-statuses' AND scope='local')
);
SET @etrn_seed_catalog = @etrn_can_seed AND @etrn_catalog IS NULL;
INSERT INTO catalogs(title,scope,kind)
SELECT 'Статусы подписания ЭТРН','local','etrn-signing-statuses' WHERE @etrn_seed_catalog;
SET @etrn_catalog = IF(@etrn_seed_catalog,LAST_INSERT_ID(),@etrn_catalog);

SET @etrn_insert_binding = @etrn_can_seed AND @etrn_catalog IS NOT NULL AND NOT EXISTS (
    SELECT 1 FROM catalog_bindings WHERE table_id=@etrn_table AND field_key='U2' AND catalog_id=@etrn_catalog
);
INSERT INTO catalog_bindings(catalog_id,table_id,field_key)
SELECT @etrn_catalog,@etrn_table,'U2' WHERE @etrn_insert_binding;

INSERT INTO catalog_entries(catalog_id,name,normalized,color)
SELECT @etrn_catalog,name,LOWER(name),color FROM (
    SELECT 1 AS position, 'НП (Не подписано Перевозом/водителем)' AS name, '#F4D9DA' AS color
    UNION ALL SELECT 2, 'ПВ (подписано водителем)', '#DCEAD4'
    UNION ALL SELECT 3, 'нПП (Не подписано Получателем)', '#F7F2D2'
    UNION ALL SELECT 4, 'ПС (подписано складом)', '#DCEAD4'
    UNION ALL SELECT 5, 'ДЗ (документооборот завершен)', '#DCEAD4'
) AS options WHERE @etrn_seed_catalog ORDER BY position;

INSERT INTO change_history(actor_name,table_id,field_key,column_label,after_value,action,category,operation_id)
SELECT 'system',@etrn_table,'U2','Статус ЭТРН',
    JSON_OBJECT('catalogId',@etrn_catalog,'columnAdded',@etrn_insert_column,'entriesAdded',IF(@etrn_seed_catalog,5,0)),
    'import','catalog','etrn_signing_status_column_v1'
WHERE @etrn_insert_column OR @etrn_seed_catalog OR @etrn_insert_binding;

COMMIT;
