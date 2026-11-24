START TRANSACTION;
SELECT value FROM workspace_meta WHERE `key`='marker' FOR UPDATE;
SET @party_table = (SELECT id FROM table_definitions WHERE slug='vyvoz' AND is_demo=FALSE);
SET @party_seed = NOT EXISTS (SELECT 1 FROM catalogs WHERE kind='edo-counterparties');

INSERT INTO catalogs(title,scope,kind)
SELECT 'Контрагенты ЭДО','local','edo-counterparties' WHERE @party_seed AND @party_table IS NOT NULL;

SET @party_catalog = (SELECT id FROM catalogs WHERE kind='edo-counterparties');
INSERT IGNORE INTO catalog_bindings(catalog_id,table_id,field_key)
SELECT @party_catalog,@party_table,fields.field_key
FROM (SELECT '' AS field_key UNION ALL SELECT 'AA' UNION ALL SELECT 'AC' UNION ALL SELECT 'AE' UNION ALL SELECT 'AG') fields
WHERE @party_catalog IS NOT NULL AND @party_table IS NOT NULL;

INSERT INTO change_history(actor_name,table_id,column_label,after_value,action,category,operation_id)
SELECT 'system',@party_table,'Контрагенты ЭДО',JSON_OBJECT('catalogId',@party_catalog),
    'create','catalog','edo_counterparty_directory_v1'
WHERE @party_seed AND @party_catalog IS NOT NULL;
COMMIT;
