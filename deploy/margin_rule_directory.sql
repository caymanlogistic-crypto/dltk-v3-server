START TRANSACTION;
SELECT value FROM workspace_meta WHERE `key`='marker' FOR UPDATE;
SET @margin_table = (SELECT id FROM table_definitions WHERE slug='vyvoz' AND is_demo=FALSE);
SET @margin_seed = NOT EXISTS (SELECT 1 FROM catalogs WHERE kind='margin-rules');

INSERT INTO catalogs(title,scope,kind)
SELECT 'Минимальная маржа','local','margin-rules' WHERE @margin_seed AND @margin_table IS NOT NULL;

SET @margin_catalog = (SELECT id FROM catalogs WHERE kind='margin-rules');
INSERT IGNORE INTO catalog_bindings(catalog_id,table_id,field_key)
SELECT @margin_catalog,@margin_table,'' WHERE @margin_catalog IS NOT NULL AND @margin_table IS NOT NULL;

INSERT INTO change_history(actor_name,table_id,column_label,after_value,action,category,operation_id)
SELECT 'system',@margin_table,'Минимальная маржа',JSON_OBJECT('catalogId',@margin_catalog),
    'create','catalog','margin_rule_directory_v1'
WHERE @margin_seed AND @margin_catalog IS NOT NULL;
COMMIT;
