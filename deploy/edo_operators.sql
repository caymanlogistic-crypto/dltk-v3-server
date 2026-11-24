START TRANSACTION;
SELECT value FROM workspace_meta WHERE `key`='marker' FOR UPDATE;

SET @edo_table = (SELECT id FROM table_definitions WHERE slug='vyvoz' AND is_demo=FALSE);
SET @edo_seed = NOT EXISTS (SELECT 1 FROM catalogs WHERE kind='edo-operators');

INSERT INTO catalogs(title,scope,kind)
SELECT 'Операторы ЭДО','local','edo-operators' WHERE @edo_seed AND @edo_table IS NOT NULL;

SET @edo_catalog = (SELECT id FROM catalogs WHERE kind='edo-operators');
INSERT IGNORE INTO catalog_bindings(catalog_id,table_id,field_key)
SELECT @edo_catalog,@edo_table,'' WHERE @edo_catalog IS NOT NULL AND @edo_table IS NOT NULL;

INSERT INTO catalog_entries(catalog_id,name,normalized)
SELECT @edo_catalog,name,code FROM (
    SELECT '2ad' AS code, 'Русь-Телеком' AS name
    UNION ALL
    SELECT '2ae', 'Калуга Астрал'
    UNION ALL
    SELECT '2ah', 'ИнфоТеКС Интернет Траст'
    UNION ALL
    SELECT '2al', 'Такском'
    UNION ALL
    SELECT '2ba', 'НТЦ СТЭК'
    UNION ALL
    SELECT '2be', 'Тензор (СБИС / Saby)'
    UNION ALL
    SELECT '2bk', 'СберКорус'
    UNION ALL
    SELECT '2bm', 'Контур.Диадок'
    UNION ALL
    SELECT '2ee', 'Электронный Экспресс'
    UNION ALL
    SELECT '2gp', 'КДС'
    UNION ALL
    SELECT '2hx', 'КРИПТЭКС'
    UNION ALL
    SELECT '2ij', 'Эдивеб'
    UNION ALL
    SELECT '2jf', 'ЭДО.МИГ24'
    UNION ALL
    SELECT '2lh', 'ФораПром'
    UNION ALL
    SELECT '2lt', 'Оператор-ЦРПТ (ЭДО Лайт)'
    UNION ALL
    SELECT '2mh', 'Точка'
    UNION ALL
    SELECT '2vo', 'Эвотор ОФД'
) AS operators WHERE @edo_seed AND @edo_catalog IS NOT NULL;

INSERT INTO change_history(actor_name,table_id,column_label,after_value,action,category,operation_id)
SELECT 'system',@edo_table,'Операторы ЭДО',JSON_OBJECT('catalogId',@edo_catalog,'entries',17),
    'import','catalog','edo_operator_directory_v1'
WHERE @edo_seed AND @edo_catalog IS NOT NULL;

COMMIT;
