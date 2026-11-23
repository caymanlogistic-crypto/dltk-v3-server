START TRANSACTION;
SELECT value FROM workspace_meta WHERE `key`='marker' FOR UPDATE;
SET @status_table = (SELECT id FROM table_definitions WHERE slug='vyvoz' AND is_demo=FALSE);

SET @status_seed = NOT EXISTS (SELECT 1 FROM catalogs WHERE kind='assignment-statuses');
INSERT INTO catalogs(title,scope,kind)
SELECT 'Статусы поручений','local','assignment-statuses' WHERE @status_seed AND @status_table IS NOT NULL;
SET @status_catalog = (SELECT id FROM catalogs WHERE kind='assignment-statuses');
INSERT IGNORE INTO catalog_bindings(catalog_id,table_id,field_key)
SELECT @status_catalog,@status_table,'T' WHERE @status_catalog IS NOT NULL AND @status_table IS NOT NULL;
INSERT INTO catalog_entries(catalog_id,name,normalized)
SELECT @status_catalog,name,LOWER(name) FROM (
    SELECT 1 AS list_position, 'ЖП (жду поручение)' AS name
    UNION ALL
    SELECT 2, 'ПП (поручение получено)'
    UNION ALL
    SELECT 3, 'РП (расписка подписана)'
    UNION ALL
    SELECT 4, 'КА ЭТРН (Клиент делает ЭТРН)'
    UNION ALL
    SELECT 5, 'НП (Не подписано Перевозом/водителем)'
    UNION ALL
    SELECT 6, 'ПВ (подписано водителем)'
    UNION ALL
    SELECT 7, 'нПП (Не подписано Получателем)'
    UNION ALL
    SELECT 8, 'ПС (подписано складом)'
    UNION ALL
    SELECT 9, 'ДЗ (документооборот завершен)'
) AS options WHERE @status_seed AND @status_catalog IS NOT NULL ORDER BY list_position;
INSERT INTO change_history(actor_name,table_id,column_label,after_value,action,category,operation_id)
SELECT 'system',@status_table,'Статусы поручений',JSON_OBJECT('catalogId',@status_catalog,'entries',9),
    'import','catalog','document_status_directories_v1'
WHERE @status_seed AND @status_catalog IS NOT NULL;

SET @status_seed = NOT EXISTS (SELECT 1 FROM catalogs WHERE kind='etrn-statuses');
INSERT INTO catalogs(title,scope,kind)
SELECT 'Статусы ЭТрН','local','etrn-statuses' WHERE @status_seed AND @status_table IS NOT NULL;
SET @status_catalog = (SELECT id FROM catalogs WHERE kind='etrn-statuses');
INSERT IGNORE INTO catalog_bindings(catalog_id,table_id,field_key)
SELECT @status_catalog,@status_table,'U' WHERE @status_catalog IS NOT NULL AND @status_table IS NOT NULL;
INSERT INTO catalog_entries(catalog_id,name,normalized)
SELECT @status_catalog,name,LOWER(name) FROM (
    SELECT 1 AS list_position, 'ОЗ (отправить заявку)' AS name
    UNION ALL
    SELECT 2, 'ЗГ (заявка готова)'
    UNION ALL
    SELECT 3, 'ОП (отправить поручение)'
    UNION ALL
    SELECT 4, 'ПГ (поручение готово)'
    UNION ALL
    SELECT 5, 'СЭТ (сделать ЭТРН)'
    UNION ALL
    SELECT 6, 'СЭТ ДЛТК (сделатьЭТРН, перевоз ДЛТК)'
    UNION ALL
    SELECT 7, 'ЭТРН (ЭТРН готова)'
) AS options WHERE @status_seed AND @status_catalog IS NOT NULL ORDER BY list_position;
INSERT INTO change_history(actor_name,table_id,column_label,after_value,action,category,operation_id)
SELECT 'system',@status_table,'Статусы ЭТрН',JSON_OBJECT('catalogId',@status_catalog,'entries',7),
    'import','catalog','document_status_directories_v1'
WHERE @status_seed AND @status_catalog IS NOT NULL;

COMMIT;
