<?php
declare(strict_types=1);

$app=dirname(__DIR__,2).'/app/local-admin';
$view=$app.'/views';
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
$styles=['vitrine.css'];
$scripts=[
    'vitrine-theme.js','vitrine-grid-scope.js','workspace-paths.js','vitrine-notifications.js',
    'vitrine.js','vitrine-display.js','vitrine-select.js','vitrine-statuses.js','vitrine-slot.js',
    'vitrine-dropoff.js','preview.js','vitrine-editor.js',
    'vitrine-rows.js','vitrine-catalog-search.js','vitrine-table-export.js','vitrine-time-picker.js','vitrine-calendar.js',
    'workspace-auth.js','vitrine-trip-driver.js',
];
$asset=substr($path,strlen('/assets/'));
if (str_starts_with($path,'/assets/') && in_array($asset,[...$styles,...$scripts],true)) {
    header('Content-Type: '.(in_array($asset,$styles,true) ? 'text/css' : 'text/javascript').'; charset=UTF-8');
    readfile($asset==='preview.js' ? __DIR__.'/'.$asset : $app.'/public/assets/'.$asset);
    return;
}
if ($path==='/favicon.ico') {
    http_response_code(204);
    return;
}
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src-attr 'unsafe-inline'; font-src 'self' data:; img-src 'self' data:; base-uri 'none'; form-action 'self'");

require_once $app.'/src/bootstrap.php';
require_once $app.'/src/WorkspaceFleet.php';
require_once $app.'/src/WorkspaceFleetDetails.php';
require_once $app.'/src/WorkspaceTripMargin.php';
require_once $app.'/src/WorkspaceEmployees.php';
require_once $app.'/src/WorkspaceCatalogs.php';
require_once __DIR__.'/validation.php';

$palette=workspace_palette();
$fillByName=array_column($palette,'fill','name');
$statuses=[];
foreach (['в пути'=>'Красный','выгружают'=>'Оранжевый','на терминале'=>'Фиолетовый',
    'ожидает сдачу'=>'Бирюзовый','сдан'=>'Зелёный'] as $name=>$color) {
    $statuses[]=['id'=>count($statuses)+1,'name'=>$name,'color'=>$fillByName[$color]];
}
$statusByName=[];
foreach ($statuses as $status) $statusByName[vitrine_clipboard_status_key($status['name'])]=$status;
$orderStatuses=[
    'work'=>['label'=>'В работу','color'=>$fillByName['Бирюзовый']],
    'preliminary'=>['label'=>'Предварительный заказ','color'=>$fillByName['Оранжевый']],
];
$dropoffProfiles=[
    'площадка а'=>['B'=>'Учебная ул., 10','D'=>'Въезд со двора.'],
    'площадка б'=>['B'=>'Учебная ул., 12','D'=>'Регистрация на въезде.'],
];
date_default_timezone_set('Europe/Moscow');
$today=new DateTimeImmutable('today');
$examples=include __DIR__.'/cases.php';
$examples=array_combine(range(1,count($examples)),$examples);
$method=$_SERVER['REQUEST_METHOD']??'GET';
if ($method==='GET' && $path==='/api/vitrine/rows' && ($_GET['tripDriver']??null)==='1') {
    $row=(int)($_GET['ids']??0);
    $sample=$examples[$row]??null;
    if (empty($sample['tripDriver'])) json_response(['error'=>['message'=>'Учебная карточка не найдена.']],404);
    $vehicle=$_GET['vehicle']??$sample['value'];
    if (!is_string($vehicle)) preview_error('Выберите машину из списка.');
    $driver=preview_choice($sample['choices'],$vehicle,true)['label'];
    [$tractor,$trailer]=array_pad(explode(' / ',$vehicle,2),2,'');
    json_response(['sections'=>[
        'Водитель'=>['ФИО'=>str_ends_with($driver,'Тестов П.') ? 'Тестов Пётр' : 'Примеров Иван','Телефон'=>'+7 (916) 000 00 00'],
        'Автотранспорт'=>['Тягач'=>$tractor,'Полуприцеп'=>$trailer],
        'Документы'=>['Паспорт'=>'00 00 000000','Водительское удостоверение'=>'00 00 000000'],
    ]]);
}
if ($method==='POST' && $path==='/api/workspace/preferences') {
    json_response(['saved'=>true]);
}
if ($method==='POST' && in_array($path,['/api/vitrine/cell','/api/vitrine/paste-check'],true)) {
    $body=file_get_contents('php://input',false,null,0,150000);
    $request=json_decode($body?:'',true);
    $column=$request['column']??null;
    $input=$request['value']??null;
    $row=$request['row']??null;
    if (!is_int($row) || !isset($examples[$row]) || $column!=='A' || !is_string($input) || mb_strlen($input)>20000) {
        preview_error('Проверьте значение ячейки.');
    }
    $sample=$examples[$row];
    if (!empty($sample['readOnly'])) preview_error('Это значение доступно только для просмотра.');
    if (isset($sample['dropoff'],$request['paste'])) {
        preview_error('Откройте поле «Сдача» и выберите точку из справочника.','invalid_dropoff');
    }
    $source=$request['paste']['source']??null;
    if ($source!==null) {
        $sourceRow=$source['row']??null;
        if (!is_int($sourceRow) || !isset($examples[$sourceRow]) || ($source['column']??null)!=='A') {
            preview_error('Скопируйте значение примера заново.','clipboard_source_missing');
        }
        if (preview_clipboard_type($examples[$sourceRow])!==preview_clipboard_type($sample)) {
            preview_error('Форматы ячеек не совпадают. Вставка отменена.','clipboard_type_mismatch');
        }
    }
    $status=null;
    $extra=[];
    try {
        $reset=($request['reset']??false)===true;
        $value=$reset ? $sample['value'] : $input;
        $type=$sample['type'];
        if ($type==='status') {
            $status=$statusByName[vitrine_clipboard_status_key($value)]??null;
            if (trim($value)!=='' && !$status) preview_error('Выберите статус из справочника.');
            $shown=$status['name']??'';
        } elseif ($type==='choice') {
            $choice=preview_choice($sample['choices'],$value,($sample['required']??false)===true);
            $shown=$choice['value'];
            $extra['catalogFill']=$choice['color']??null;
            if (array_key_exists('initialOrderStatus',$sample)) {
                $orderCode=$request['orderStatus']??$sample['initialOrderStatus'];
                if (!is_string($orderCode) || ($orderCode!=='' && !isset($orderStatuses[$orderCode]))) {
                    preview_error('Выберите статус заказа из списка.');
                }
                $extra['orderStatus']=$shown==='' ? '' : $orderCode;
                $extra['catalogFill']=null;
                $extra['orderFill']=$orderStatuses[$extra['orderStatus']]['color']??'';
            }
            if (isset($sample['dropoff'])) {
                $extra['referenceId']=$shown==='' ? 0 : 1;
                $extra['dropoffDetails']=$shown==='' ? [] : workspace_dropoff_flight_details($request['dropoffDetails']??$sample['dropoff']);
            }
            if (!empty($sample['tripDriver'])) $extra['referenceId']=$shown==='' ? 0 : 1;
        } else {
            $shown=$reset ? $value : preview_value($sample,$value,isset($request['paste']),$today);
        }
    } catch (ApiError $error) {
        preview_error($error->publicMessage,$error->publicCode);
    }
    if ($path==='/api/vitrine/paste-check') {
        $pasteResult=['accepted'=>true];
        if (in_array($type,['vehicle-brand','plate','trailer','passport'],true)) $pasteResult['editorValue']=$shown;
        json_response($pasteResult);
    }
    json_response([
        'value'=>$shown,'original'=>$sample['value'],'baseHash'=>'preview',
        'revision'=>(int)($request['revision']??0)+1,'edited'=>$shown!==$sample['value'],
        'statusColor'=>$status['color']??null,'statusId'=>$status['id']??null,
    ]+$extra);
}
if ($method!=='GET' || !in_array($path,['/','/api/workspace/manifest','/api/vitrine/rows'],true)) {
    http_response_code(404);
    return;
}

$escape=static fn(mixed $value): string=>htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$columnClass=static fn(array $column): string=>$column['letter']==='G' ? ' vitrine-time-column' : '';
$columns=json_decode(file_get_contents(__DIR__.'/columns.json'),true,flags:JSON_THROW_ON_ERROR);
foreach ($columns as &$column) {
    if ($column['letter']==='A') $column['label']='Пример';
    if ($column['letter']==='B') $column['label']='Правила';
    if ($column['letter']==='C') $column['label']='Оценка';
}
unset($column);
$currentTable=['title'=>'Автовывоз','slug'=>'vyvoz'];
$data=['table'=>$currentTable,'sheetTitle'=>'Автовывоз'];
$directory=$catalogGrid=$tripDraft=null;
$isAvtovyvoz=$showDraftRows=false;
$rowArchive=($_GET['archive']??null)==='1';
$gridWidth=$defaultGridWidth='full';
$rows=$sharedChoices=$calendars=$editorTypes=$formats=[];
$formatLabels=[''=>'Все форматы','date'=>'Дата и время','select'=>'Списки',
    'number'=>'Числа','text'=>'Текст','view'=>'Только просмотр'];
$ratingLabels=[''=>'Все оценки','10'=>'10','9,8'=>'9,8','9,5'=>'9,5'];
$filterValue=static fn(string $name): string=>is_string($_GET[$name]??null) ? trim($_GET[$name]) : '';
$query=$filterValue('q');
$formatFilter=$filterValue('format');
$ratingFilter=$filterValue('rating');
$ratings=require __DIR__.'/ratings.php';
foreach ($examples as $row=>$sample) {
    $type=$sample['type'];
    $editorTypes[$row]=$type;
    $calendar=preview_calendar($sample,$today);
    if ($calendar!==null) $calendars[$row]=$calendar;
    $formats[$row]=match (true) {
        !empty($sample['readOnly'])=>'view',
        in_array($type,['status','choice'],true)=>'select',
        $calendar!==null || $type==='time'=>'date',
        in_array($type,['weight','distance','money','expense','minimum','cargo-value','cargo-places','percent'],true)=>'number',
        default=>'text',
    };
    $score=$ratings[$sample['label']]??null;
    $rating=$score===null ? '—' : rtrim(rtrim(number_format($score,1,',',''),'0'),',');
    $mask=match ($type) {
        'rule-date'=>'date',
        'expense','minimum'=>'money',
        'phone','date','time','weight','distance','money','cargo-value','cargo-places','percent'=>$type,
        default=>null,
    };
    $status=$type==='status' ? $statusByName[vitrine_clipboard_status_key($sample['value'])] : null;
    $choices=$sample['choices']??[];
    if (!empty($sample['search'])) {
        $choices=array_map(static fn($choice): array=>(is_array($choice) ? $choice : ['value'=>$choice])+['previewSearch'=>true],$choices);
    }
    $shared=$sample['shared']??null;
    if ($shared!==null) $sharedChoices[$shared]=$choices;
    $chosen=$type==='choice' ? preview_choice($choices,$sample['value'],false) : [];
    $fill=$chosen['color']??'';
    if (array_key_exists('initialOrderStatus',$sample)) {
        $code=$sample['initialOrderStatus'];
        $fill=$orderStatuses[$code]['color']??'';
    }
    $readOnly=$sample['readOnly']??false;
    $rule=$sample['rule'];
    $singleLine=!$readOnly && !in_array($type,['choice','status','slot','text'],true);
    $model=[
        'rowIndex'=>$row,'rowLabel'=>$sample['label'],'values'=>['A'=>$sample['value'],'B'=>$rule,'C'=>$rating],
        'baseHash'=>'preview','singleLine'=>$singleLine ? ['A'=>true] : [],
        'compactInputs'=>$mask ? ['A'=>$mask] : [],
        'draftTypes'=>in_array($type,['date','phone','email'],true) ? ['A'=>$type] : [],
        'kinds'=>['A'=>$type==='choice' || $type==='expense' || $type==='minimum' ? ($mask??'text') : $type],
        'statusId'=>$status['id']??null,'statusColor'=>$status['color']??null,
        'choices'=>$choices ? ['A'=>$choices] : [],
        'requiredChoices'=>!empty($sample['required']) ? ['A'=>true] : [],
        'sharedChoices'=>$shared!==null ? ['A'=>$shared] : [],
        'catalogFills'=>$fill!=='' ? ['A'=>$fill] : [],
        'readOnly'=>array_fill_keys(array_diff(array_column($columns,'letter'),$readOnly ? [] : ['A']),
            'Это значение доступно только для просмотра.'),
    ];
    if (isset($sample['dropoff'])) {
        $model['dropoffDetails']=$sample['dropoff'];
        $model['catalogBindings']=['A'=>1];
        $model['references']=['A'=>['id'=>1]];
    }
    if (!empty($sample['tripDriver'])) $model['tripDriverCards']=['A'=>true];
    if (array_key_exists('initialOrderStatus',$sample)) $model['orderStatuses']=['A'=>$sample['initialOrderStatus']];
    if (isset($sample['derived'])) $model['derivedSources']=['A'=>$sample['derived']];
    $rows[]=$model;
}
$rows=$rowArchive ? [] : array_values(array_filter($rows,static fn(array $row): bool=>
    ($formatFilter==='' || !isset($formatLabels[$formatFilter]) || $formats[$row['rowIndex']]===$formatFilter)
    && ($ratingFilter==='' || !isset($ratingLabels[$ratingFilter]) || $row['values']['C']===$ratingFilter)
    && ($query==='' || mb_stripos($row['rowLabel'].' '.implode(' ',$row['values']),$query)!==false)));
if ($path==='/api/workspace/manifest') json_response(['rowIds'=>array_column($rows,'rowIndex')]);
if ($path==='/api/vitrine/rows') {
    $requested=array_fill_keys(explode(',',$filterValue('ids')),true);
    $rows=array_filter($rows,static fn(array $row): bool=>isset($requested[$row['rowIndex']]));
    ob_start();
    require $view.'/vitrine-rows.php';
    json_response(['rowIds'=>array_column($rows,'rowIndex'),'html'=>ob_get_clean()]);
}
$preferences=['layoutOverrides'=>['pinned'=>[],'widths'=>['A'=>260,'B'=>770,'C'=>110]]];
if (($_GET['preset']??'')==='night') $preferences['colorPreset']=['value'=>'night','version'=>1];
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="app-base-path" content="">
  <meta id="workspace-preferences" content="<?= $escape(json_encode($preferences)) ?>">
  <meta id="vitrine-row-manifest" content="<?= $escape(json_encode(array_column($rows,'rowIndex'))) ?>">
  <meta id="vitrine-status-data" content="<?= $escape(json_encode(compact('statuses','palette'))) ?>">
  <meta id="vitrine-order-statuses" content="<?= $escape(json_encode($orderStatuses)) ?>">
  <meta id="vitrine-shared-options" content="<?= $escape(json_encode($sharedChoices)) ?>">
  <meta id="vitrine-dropoff-options" content="<?= $escape(json_encode($dropoffProfiles)) ?>">
  <meta id="vitrine-preview-calendars" content="<?= $escape(json_encode($calendars)) ?>">
  <meta id="vitrine-preview-presentation" content="<?= $escape(json_encode($editorTypes)) ?>">
  <title>Автовывоз — проверка форматов</title>
  <link rel="stylesheet" href="/assets/vitrine.css">
  <?php foreach ($scripts as $script): ?>
  <script defer src="/assets/<?= $escape($script) ?>"></script>
  <?php endforeach; ?>
</head>
<body class="vitrine" data-workspace-user="preview" data-workspace-table="vyvoz">
<div class="app app--workspace">
  <header class="vitrine-topbar">
    <a class="sidebar-brand vitrine-brand" href="/"><span class="logo-mark" aria-hidden="true"></span><span class="brand-name">ЛК ДЛТК</span></a>
    <nav class="vitrine-topnav" aria-label="Основная навигация">
      <div class="vitrine-nav-start">
        <a class="nav-item is-active" href="/"><svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM3 10h18M10 3v18"/></svg><span class="nav-label">Автовывоз</span></a>
      </div>
    </nav>
  </header>
  <main class="content ui-detail-card" id="workspace" tabindex="-1">
    <section class="vitrine-catalog-toolbar" aria-label="Управление таблицей">
      <h1>Таблица: <strong>Автовывоз</strong></h1>
      <form class="vitrine-catalog-search" method="get" action="/">
        <?php foreach ([
            'archive'=>['Фильтр записей',['0'=>'Активные','1'=>'Архив']],
            'format'=>['Фильтр по формату',$formatLabels],
            'rating'=>['Фильтр по оценке',$ratingLabels],
        ] as $parameter=>[$label,$options]): ?>
        <div class="vitrine-directory-visibility" data-vitrine-filter-param="<?= $escape($parameter) ?>"
             data-filter-label="<?= $escape($label) ?>"
             data-filter-options="<?= $escape(json_encode(array_map(static fn($value,$label): array=>['value'=>(string)$value,'label'=>$label],array_keys($options),array_values($options)))) ?>"></div>
        <?php endforeach; ?>
        <input class="field-input" type="search" name="q" maxlength="160" value="<?= $escape($query) ?>"
               aria-label="Поиск по таблице" placeholder="Поиск по таблице">
        <?php require $view.'/vitrine-export-button.php'; ?>
      </form>
    </section>
    <?php require $view.'/vitrine-clear-cell-dialog.php'; ?>
    <?php require $view.'/vitrine-hide-column-dialog.php'; ?>
    <?php require $view.'/vitrine-trip-driver-dialog.php'; ?>
    <template id="vitrine-preview-password-eye">
      <?php $passwordEyeField='vitrine-editor-field'; require $view.'/password-eye.php'; ?>
    </template>
    <?php require $view.'/vitrine-table.php'; ?>
  </main>
</div>
</body>
</html>
