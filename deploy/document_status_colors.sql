START TRANSACTION;
SELECT value FROM workspace_meta WHERE `key`='marker' FOR UPDATE;
SET @status_table = (SELECT id FROM table_definitions WHERE slug='vyvoz' AND is_demo=FALSE);
SET @color_seed = NOT EXISTS (SELECT 1 FROM workspace_meta WHERE `key`='document_status_colors_v1');
SET @etrn_catalog = (SELECT c.id FROM catalogs c JOIN catalog_bindings b ON b.catalog_id=c.id
    WHERE c.kind='etrn-statuses' AND b.table_id=@status_table AND b.field_key='U');

INSERT INTO change_history(actor_name,table_id,column_label,before_value,after_value,action,category,operation_id)
SELECT 'system',@status_table,'Статусы ЭТрН',JSON_OBJECT('id',id,'color',color),
    JSON_OBJECT('id',id,'color',IF(normalized='зг (заявка готова)','#F7F2D2','#EFD9E8')),
    'edit','catalog','document_status_colors_v1'
FROM catalog_entries WHERE catalog_id=@etrn_catalog AND @color_seed AND active AND color IS NULL
    AND normalized IN ('зг (заявка готова)','сэт длтк (сделатьэтрн, перевоз длтк)');

UPDATE catalog_entries SET color=IF(normalized='зг (заявка готова)','#F7F2D2','#EFD9E8'),revision=revision+1
WHERE catalog_id=@etrn_catalog AND @color_seed AND active AND color IS NULL
    AND normalized IN ('зг (заявка готова)','сэт длтк (сделатьэтрн, перевоз длтк)');

INSERT IGNORE INTO workspace_meta (`key`,value)
SELECT 'document_status_colors_v1','true' WHERE @etrn_catalog IS NOT NULL;
COMMIT;
