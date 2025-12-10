<?php
/**
 * Шаг 3 установки: Пошаговый процесс с детальным логированием
 * Версия 1.0.3
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Catalog\MeasureTable;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

global $APPLICATION;

Loader::includeModule('iblock');
Loader::includeModule('catalog');

$moduleId = 'prospektweb.calc';

// Инициализация сессии
if (! isset($_SESSION['PROSPEKTWEB_CALC_INSTALL'])) {
    $_SESSION['PROSPEKTWEB_CALC_INSTALL'] = [
        'product_iblock_id' => (int)($_REQUEST['PRODUCT_IBLOCK_ID'] ?? 0),
        'sku_iblock_id' => (int)($_REQUEST['SKU_IBLOCK_ID'] ?? 0),
        'create_demo_data' => ($_REQUEST['CREATE_DEMO_DATA'] ?? '') === 'Y',
        'current_step' => 1,
        'iblock_ids' => [],
        'log' => [],
        'errors' => [],
    ];
}

$installData = &$_SESSION['PROSPEKTWEB_CALC_INSTALL'];
$currentStep = (int)($_REQUEST['install_step'] ?? $installData['current_step']);

// Очищаем лог для нового шага
$installData['log'] = [];

// Функция логирования
function installLog(string $message, string $type = 'info'): void
{
    $_SESSION['PROSPEKTWEB_CALC_INSTALL']['log'][] = ['message' => $message, 'type' => $type];
}

// Функция получения ошибки Bitrix
function getBitrixError(): string
{
    global $APPLICATION;
    $ex = $APPLICATION->GetException();
    return $ex ? $ex->GetString() : 'Неизвестная ошибка';
}

// Создание типа инфоблоков
function createIblockTypeWithLog(string $id, string $name): bool
{
    $type = \CIBlockType::GetByID($id)->Fetch();
    if ($type) {
        installLog("Тип инфоблоков '{$id}' уже существует", 'warning');
        return true;
    }

    $arFields = [
        'ID' => $id,
        'SECTIONS' => 'Y',
        'IN_RSS' => 'N',
        'SORT' => 500,
        'LANG' => [
            'ru' => ['NAME' => $name, 'SECTION_NAME' => 'Разделы', 'ELEMENT_NAME' => 'Элементы'],
            'en' => ['NAME' => $name, 'SECTION_NAME' => 'Sections', 'ELEMENT_NAME' => 'Elements'],
        ],
    ];

    $obBlockType = new \CIBlockType();
    $result = $obBlockType->Add($arFields);
    
    if ($result) {
        installLog("Создан тип инфоблоков '{$id}'", 'success');
        return true;
    } else {
        $error = getBitrixError();
        installLog("Ошибка создания типа '{$id}': {$error}", 'error');
        $_SESSION['PROSPEKTWEB_CALC_INSTALL']['errors'][] = "Тип '{$id}': {$error}";
        return false;
    }
}

// Создание инфоблока
function createIblockWithLog(string $typeId, string $code, string $name, array $properties = []): int
{
    installLog("Обработка инфоблока '{$code}'.. .");
    
    $rsIBlock = \CIBlock::GetList([], ['CODE' => $code, 'TYPE' => $typeId]);
    if ($arIBlock = $rsIBlock->Fetch()) {
        $id = (int)$arIBlock['ID'];
        installLog("Инфоблок '{$code}' уже существует (ID: {$id})", 'warning');
        return $id;
    }

    $siteId = \CSite::GetDefSite();

    $arFields = [
        'ACTIVE' => 'Y',
        'NAME' => $name,
        'CODE' => $code,
        'IBLOCK_TYPE_ID' => $typeId,
        'SITE_ID' => [$siteId],
        'SORT' => 500,
        'VERSION' => 2,
        'GROUP_ID' => ['1' => 'X', '2' => 'R'],
    ];

    $iblock = new \CIBlock();
    $iblockId = $iblock->Add($arFields);

    if (! $iblockId) {
        $error = getBitrixError();
        installLog("ОШИБКА создания инфоблока '{$code}': {$error}", 'error');
        $_SESSION['PROSPEKTWEB_CALC_INSTALL']['errors'][] = "Инфоблок '{$code}': {$error}";
        return 0;
    }

    installLog("Создан инфоблок '{$code}' (ID: {$iblockId})", 'success');

    // Создаём свойства
    $propsCreated = 0;
    foreach ($properties as $propCode => $propData) {
        $arProperty = [
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
            'CODE' => $propCode,
            'NAME' => $propData['NAME'],
            'PROPERTY_TYPE' => $propData['TYPE'] ?? 'S',
            'MULTIPLE' => $propData['MULTIPLE'] ?? 'N',
            'SORT' => $propData['SORT'] ?? 500,
        ];

        if (isset($propData['USER_TYPE'])) {
            $arProperty['USER_TYPE'] = $propData['USER_TYPE'];
        }
        if (isset($propData['LINK_IBLOCK_ID'])) {
            $arProperty['LINK_IBLOCK_ID'] = $propData['LINK_IBLOCK_ID'];
        }
        if (isset($propData['VALUES'])) {
            $arProperty['VALUES'] = $propData['VALUES'];
        }

        $ibp = new \CIBlockProperty();
        if ($ibp->Add($arProperty)) {
            $propsCreated++;
        }
    }
    
    if (count($properties) > 0) {
        installLog("  → Свойства: {$propsCreated}/" . count($properties), $propsCreated === count($properties) ?  'success' : 'warning');
    }

    return $iblockId;
}

// Создание SKU-связи
function createSkuRelationWithLog(int $productIblockId, int $offersIblockId, string $name): bool
{
    if ($productIblockId <= 0 || $offersIblockId <= 0) {
        installLog("Пропуск SKU-связи '{$name}': некорректные ID", 'warning');
        return false;
    }

    installLog("Настройка SKU-связи '{$name}' ({$productIblockId} → {$offersIblockId}).. .");

    $existingInfo = \CCatalogSKU::GetInfoByProductIBlock($productIblockId);
    if ($existingInfo && (int)$existingInfo['IBLOCK_ID'] === $offersIblockId) {
        installLog("SKU-связь '{$name}' уже существует", 'warning');
        return true;
    }

    $propertyCode = 'CML2_LINK';
    $rsProperty = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $offersIblockId, 'CODE' => $propertyCode]);
    
    if (! $rsProperty->Fetch()) {
        $arProperty = [
            'IBLOCK_ID' => $offersIblockId,
            'ACTIVE' => 'Y',
            'CODE' => $propertyCode,
            'NAME' => 'Элемент каталога',
            'PROPERTY_TYPE' => 'E',
            'MULTIPLE' => 'N',
            'LINK_IBLOCK_ID' => $productIblockId,
            'SORT' => 5,
        ];
        $ibp = new \CIBlockProperty();
        $propId = $ibp->Add($arProperty);
        if (! $propId) {
            installLog("Ошибка создания свойства CML2_LINK: " . getBitrixError(), 'error');
            return false;
        }
        installLog("  → Создано свойство CML2_LINK (ID: {$propId})", 'success');
    } else {
        $rsProperty = \CIBlockProperty::GetList([], ['IBLOCK_ID' => $offersIblockId, 'CODE' => $propertyCode]);
        $arProp = $rsProperty->Fetch();
        $propId = $arProp['ID'];
        installLog("  → Свойство CML2_LINK существует (ID: {$propId})", 'warning');
    }

    $arCatalog = [
        'IBLOCK_ID' => $offersIblockId,
        'PRODUCT_IBLOCK_ID' => $productIblockId,
        'SKU_PROPERTY_ID' => $propId,
    ];

    $result = \CCatalog::Add($arCatalog);
    if ($result) {
        installLog("SKU-связь '{$name}' создана", 'success');
        return true;
    } else {
        installLog("Ошибка SKU-связи '{$name}': " . getBitrixError(), 'error');
        return false;
    }
}

// Создание единиц измерения
function createMeasuresWithLog(): bool
{
    if (!\Bitrix\Main\Loader::includeModule('catalog')) {
        installLog("ОШИБКА: Модуль catalog не загружен", 'error');
        return false;
    }

    installLog("Создание единиц измерения...", 'header');

    $measures = [
        ['CODE' => 'SHEET', 'MEASURE_TITLE' => 'Лист', 'SYMBOL_RUS' => 'л.', 'SYMBOL_INTL' => 'sheet', 'IS_DEFAULT' => 'N'],
        ['CODE' => 'PACKAGE', 'MEASURE_TITLE' => 'Упаковка', 'SYMBOL_RUS' => 'уп.', 'SYMBOL_INTL' => 'pack', 'IS_DEFAULT' => 'N'],
        ['CODE' => 'ROLL', 'MEASURE_TITLE' => 'Рулон', 'SYMBOL_RUS' => 'рул.', 'SYMBOL_INTL' => 'roll', 'IS_DEFAULT' => 'N'],
        ['CODE' => 'ROLE', 'MEASURE_TITLE' => 'Роль', 'SYMBOL_RUS' => 'роль', 'SYMBOL_INTL' => 'role', 'IS_DEFAULT' => 'N'],
        ['CODE' => 'SQM', 'MEASURE_TITLE' => 'Квадратный метр', 'SYMBOL_RUS' => 'м²', 'SYMBOL_INTL' => 'm²', 'IS_DEFAULT' => 'N'],
        ['CODE' => 'SQCM', 'MEASURE_TITLE' => 'Квадратный сантиметр', 'SYMBOL_RUS' => 'см²', 'SYMBOL_INTL' => 'cm²', 'IS_DEFAULT' => 'N'],
        ['CODE' => 'SQDM', 'MEASURE_TITLE' => 'Квадратный дециметр', 'SYMBOL_RUS' => 'дм²', 'SYMBOL_INTL' => 'dm²', 'IS_DEFAULT' => 'N'],
        ['CODE' => 'CIRCULATION', 'MEASURE_TITLE' => 'Тираж', 'SYMBOL_RUS' => 'тираж', 'SYMBOL_INTL' => 'circulation', 'IS_DEFAULT' => 'N'],
        ['CODE' => 'RUN', 'MEASURE_TITLE' => 'Прогон', 'SYMBOL_RUS' => 'прогон', 'SYMBOL_INTL' => 'run', 'IS_DEFAULT' => 'N'],
    ];

    $createdCount = 0;
    $updatedCount = 0;
    
    foreach ($measures as $measureData) {
        // Сначала ищем по нашему CODE
        $existingByCode = MeasureTable::getList([
            'filter' => ['=CODE' => $measureData['CODE']],
            'select' => ['ID', 'CODE', 'MEASURE_TITLE', 'SYMBOL_INTL', 'SYMBOL_RUS'],
            'limit' => 1,
        ])->fetch();

        if ($existingByCode) {
            installLog("  → Единица измерения '{$measureData['MEASURE_TITLE']}' уже существует (ID: {$existingByCode['ID']}, CODE: {$existingByCode['CODE']})", 'warning');
            continue;
        }

        // Ищем по SYMBOL_INTL или SYMBOL_RUS (могла быть создана стандартная единица без нашего CODE)
        $existingBySymbol = MeasureTable::getList([
            'filter' => [
                [
                    'LOGIC' => 'OR',
                    '=SYMBOL_INTL' => $measureData['SYMBOL_INTL'],
                    '=SYMBOL_RUS' => $measureData['SYMBOL_RUS'],
                ],
            ],
            'select' => ['ID', 'CODE', 'MEASURE_TITLE', 'SYMBOL_INTL', 'SYMBOL_RUS'],
            'limit' => 1,
        ])->fetch();

        if ($existingBySymbol) {
            // Единица существует, но без нашего CODE или с пустым CODE - обновляем CODE
            if (empty($existingBySymbol['CODE'])) {
                $updateResult = MeasureTable::update($existingBySymbol['ID'], ['CODE' => $measureData['CODE']]);
                
                if ($updateResult->isSuccess()) {
                    installLog("  → Обновлён CODE для '{$measureData['MEASURE_TITLE']}' (ID: {$existingBySymbol['ID']}, CODE: {$measureData['CODE']})", 'success');
                    $updatedCount++;
                } else {
                    installLog("  → Ошибка обновления CODE для '{$measureData['MEASURE_TITLE']}': " . implode('; ', $updateResult->getErrorMessages()), 'error');
                }
            } else {
                installLog("  → Единица измерения '{$measureData['MEASURE_TITLE']}' существует с другим CODE (ID: {$existingBySymbol['ID']}, CODE: {$existingBySymbol['CODE']})", 'warning');
            }
            continue;
        }

        // Единицы нет - создаём новую
        $result = MeasureTable::add($measureData);

        if ($result->isSuccess()) {
            installLog("  → Создана: {$measureData['MEASURE_TITLE']} ({$measureData['SYMBOL_RUS']})", 'success');
            $createdCount++;
        } else {
            $errors = implode('; ', $result->getErrorMessages());
            if ($errors === '') {
                $errors = getBitrixError();
            }

            installLog("  → Ошибка создания: {$measureData['MEASURE_TITLE']} ({$errors})", 'error');
        }
    }

    $total = count($measures);
    $processed = $createdCount + $updatedCount;
    installLog("Создано: {$createdCount}, обновлено: {$updatedCount} из {$total}", $processed === $total ? 'success' : 'warning');
    return true;
}

// ============= ВЫПОЛНЕНИЕ ШАГОВ =============

$totalSteps = 5;

switch ($currentStep) {
    case 1:
        installLog("ШАГ 1 из {$totalSteps}: СОЗДАНИЕ ТИПОВ ИНФОБЛОКОВ", 'header');
        installLog("Модуль: {$moduleId}");
        installLog("Сайт по умолчанию: " . \CSite::GetDefSite());
        
        createIblockTypeWithLog('calculator', 'Калькулятор');
        createIblockTypeWithLog('calculator_catalog', 'Справочники калькулятора');
        
        installLog("--- Шаг 1 выполнен ---", 'header');
        break;

    case 2:
        installLog("ШАГ 2 из {$totalSteps}: СОЗДАНИЕ ИНФОБЛОКОВ", 'header');
        
        $configProps = [
            'STATUS' => ['NAME' => 'Статус', 'TYPE' => 'L', 'VALUES' => [['VALUE' => 'draft'], ['VALUE' => 'active'], ['VALUE' => 'recalc']]],
            'LAST_CALC_DATE' => ['NAME' => 'Дата последнего расчёта', 'TYPE' => 'S', 'USER_TYPE' => 'DateTime'],
            'TOTAL_COST' => ['NAME' => 'Итоговая себестоимость', 'TYPE' => 'N'],
            'STRUCTURE' => ['NAME' => 'Структура', 'TYPE' => 'S', 'USER_TYPE' => 'HTML'],
            'USED_MATERIALS' => ['NAME' => 'Использованные материалы', 'TYPE' => 'E', 'MULTIPLE' => 'Y'],
            'USED_OPERATIONS' => ['NAME' => 'Использованные операции', 'TYPE' => 'E', 'MULTIPLE' => 'Y'],
            'USED_EQUIPMENT' => ['NAME' => 'Использованное оборудование', 'TYPE' => 'E', 'MULTIPLE' => 'Y'],
            'USED_DETAILS' => ['NAME' => 'Использованные детали', 'TYPE' => 'E', 'MULTIPLE' => 'Y'],
        ];
        
        $settingsProps = [
            'CALCULATOR_CODE' => ['NAME' => 'Код калькулятора', 'TYPE' => 'S'],
            'DEFAULT_EQUIPMENT' => ['NAME' => 'Оборудование по умолчанию', 'TYPE' => 'E'],
            'DEFAULT_MATERIAL' => ['NAME' => 'Материал по умолчанию', 'TYPE' => 'E'],
            'DEFAULT_OPTIONS' => ['NAME' => 'Опции по умолчанию', 'TYPE' => 'S', 'USER_TYPE' => 'HTML'],
            'CAN_BE_FIRST' => ['NAME' => 'Может быть первым', 'TYPE' => 'L', 'VALUES' => [['VALUE' => 'Y'], ['VALUE' => 'N']]],
            'REQUIRES_BEFORE' => ['NAME' => 'Требует перед собой', 'TYPE' => 'S'],
        ];
        
        $catalogProps = [
            'DENSITY' => ['NAME' => 'Плотность', 'TYPE' => 'N'],
        ];
        
        $operationsProps = array_merge($catalogProps, [
            'EQUIPMENTS' => ['NAME' => 'Оборудование', 'TYPE' => 'E', 'MULTIPLE' => 'Y'],
        ]);
        
        $equipmentProps = [
            'FIELDS' => ['NAME' => 'Поля печатной машины', 'TYPE' => 'S'],
            'MAX_WIDTH' => ['NAME' => 'Макс. ширина, мм', 'TYPE' => 'N'],
            'MAX_LENGTH' => ['NAME' => 'Макс. длина, мм', 'TYPE' => 'N'],
            'START_COST' => ['NAME' => 'Стоимость приладки', 'TYPE' => 'N'],
        ];

        $installData['iblock_ids']['CALC_CONFIG'] = createIblockWithLog('calculator', 'CALC_CONFIG', 'Конфигурации калькуляций', $configProps);
        $installData['iblock_ids']['CALC_SETTINGS'] = createIblockWithLog('calculator', 'CALC_SETTINGS', 'Настройки калькуляторов', $settingsProps);
        $installData['iblock_ids']['CALC_MATERIALS'] = createIblockWithLog('calculator_catalog', 'CALC_MATERIALS', 'Материалы', $catalogProps);
        $installData['iblock_ids']['CALC_MATERIALS_VARIANTS'] = createIblockWithLog('calculator_catalog', 'CALC_MATERIALS_VARIANTS', 'Варианты материалов', $catalogProps);
        $installData['iblock_ids']['CALC_OPERATIONS'] = createIblockWithLog('calculator_catalog', 'CALC_OPERATIONS', 'Операции', $operationsProps);
        $installData['iblock_ids']['CALC_OPERATIONS_VARIANTS'] = createIblockWithLog('calculator_catalog', 'CALC_OPERATIONS_VARIANTS', 'Варианты операций', $operationsProps);
        $installData['iblock_ids']['CALC_EQUIPMENT'] = createIblockWithLog('calculator_catalog', 'CALC_EQUIPMENT', 'Оборудование', $equipmentProps);
        $installData['iblock_ids']['CALC_DETAILS'] = createIblockWithLog('calculator_catalog', 'CALC_DETAILS', 'Детали', $catalogProps);
        $installData['iblock_ids']['CALC_DETAILS_VARIANTS'] = createIblockWithLog('calculator_catalog', 'CALC_DETAILS_VARIANTS', 'Варианты деталей', $catalogProps);

        $created = count(array_filter($installData['iblock_ids'], fn($id) => $id > 0));
        installLog("Создано инфоблоков: {$created}/9", $created === 9 ? 'success' : 'warning');
        
        // Создание единиц измерения
        installLog("");
        createMeasuresWithLog();
        
        installLog("--- Шаг 2 выполнен ---", 'header');
        break;

    case 3:
        installLog("ШАГ 3 из {$totalSteps}: НАСТРОЙКА SKU-СВЯЗЕЙ", 'header');
        
        $ids = $installData['iblock_ids'];
        createSkuRelationWithLog($ids['CALC_MATERIALS'] ??  0, $ids['CALC_MATERIALS_VARIANTS'] ??  0, 'Материалы');
        createSkuRelationWithLog($ids['CALC_OPERATIONS'] ?? 0, $ids['CALC_OPERATIONS_VARIANTS'] ?? 0, 'Операции');
        createSkuRelationWithLog($ids['CALC_DETAILS'] ?? 0, $ids['CALC_DETAILS_VARIANTS'] ?? 0, 'Детали');
        
        installLog("--- Шаг 3 выполнен ---", 'header');
        break;

    case 4:
        installLog("ШАГ 4 из {$totalSteps}: СОХРАНЕНИЕ НАСТРОЕК", 'header');
        
        foreach ($installData['iblock_ids'] as $code => $id) {
            if ($id > 0) {
                Option::set($moduleId, 'IBLOCK_' . $code, $id);
                installLog("Сохранено: IBLOCK_{$code} = {$id}", 'success');
            }
        }
        
        Option::set($moduleId, 'PRODUCT_IBLOCK_ID', $installData['product_iblock_id']);
        Option::set($moduleId, 'SKU_IBLOCK_ID', $installData['sku_iblock_id']);
        installLog("Сохранено: PRODUCT_IBLOCK_ID = " . $installData['product_iblock_id'], 'success');
        installLog("Сохранено: SKU_IBLOCK_ID = " . $installData['sku_iblock_id'], 'success');
        
        // Создание демо-данных (если выбрано)
        if ($installData['create_demo_data']) {
            installLog("");
            installLog("Создание демо-данных...", 'header');
            
            // Добавляем прямой require для DemoDataCreator перед использованием
            $demoDataCreatorPath = __DIR__ . '/../lib/Install/DemoDataCreator.php';
            if (file_exists($demoDataCreatorPath)) {
                require_once $demoDataCreatorPath;
                
                if (class_exists('\\Prospektweb\\Calc\\Install\\DemoDataCreator')) {
                    if (empty($installData['iblock_ids'])) {
                        installLog("ОШИБКА: Инфоблоки не созданы", 'error');
                        $_SESSION['PROSPEKTWEB_CALC_INSTALL']['errors'][] = "Инфоблоки не созданы";
                    } else {
                        $demoCreator = new \Prospektweb\Calc\Install\DemoDataCreator();
                        $demoResult = $demoCreator->create($installData['iblock_ids']);
                        
                        foreach ($demoResult['created'] as $createdMessage) {
                            installLog($createdMessage, 'success');
                        }
                        
                        foreach ($demoResult['errors'] as $errorMessage) {
                            installLog($errorMessage, 'error');
                            $_SESSION['PROSPEKTWEB_CALC_INSTALL']['errors'][] = $errorMessage;
                        }
                        
                        $totalCreated = count($demoResult['created']);
                        installLog("Всего создано элементов: {$totalCreated}", 'success');
                    }
                } else {
                    installLog("Класс DemoDataCreator не найден после загрузки файла", 'error');
                    $_SESSION['PROSPEKTWEB_CALC_INSTALL']['errors'][] = "Класс DemoDataCreator не найден после загрузки файла";
                }
            } else {
                installLog("Файл DemoDataCreator.php не найден: " . $demoDataCreatorPath, 'error');
                $_SESSION['PROSPEKTWEB_CALC_INSTALL']['errors'][] = "Файл DemoDataCreator.php не найден: " . $demoDataCreatorPath;
            }
        }
        
        installLog("--- Шаг 4 выполнен ---", 'header');
        break;

    case 5:
        installLog("ШАГ 5 из {$totalSteps}: УСТАНОВКА ФАЙЛОВ И СОБЫТИЙ", 'header');
        
        if (!class_exists('prospektweb_calc')) {
            include_once __DIR__ . '/index.php';
        }
        
        $moduleClass = new prospektweb_calc();
        
        installLog("Проверка директорий assets...");
        $assetsJsDir = __DIR__ . '/assets/js';
        $assetsCssDir = __DIR__ . '/assets/css';
        $toolsDir = dirname(__DIR__) . '/tools';
        
        if (is_dir($assetsJsDir)) {
            installLog("  → Директория JS найдена: {$assetsJsDir}", 'success');
        } else {
            installLog("  → Директория JS не найдена: {$assetsJsDir}", 'warning');
        }
        
        if (is_dir($assetsCssDir)) {
            installLog("  → Директория CSS найдена: {$assetsCssDir}", 'success');
        } else {
            installLog("  → Директория CSS не найдена: {$assetsCssDir}", 'warning');
        }
        
        if (is_dir($toolsDir)) {
            installLog("  → Директория Tools найдена: {$toolsDir}", 'success');
        } else {
            installLog("  → Директория Tools не найдена: {$toolsDir}", 'warning');
        }
        
        installLog("Копирование файлов...");
        $filesResult = $moduleClass->installFiles();
        if ($filesResult) {
            installLog("  → JS скопированы в /local/js/prospektweb.calc/", 'success');
            installLog("  → CSS скопированы в /local/css/prospektweb.calc/", 'success');
            installLog("  → Tools скопированы в /local/tools/prospektweb.calc/", 'success');
        } else {
            installLog("Некоторые файлы не были скопированы (возможно, отсутствуют исходные директории)", 'warning');
        }
        
        installLog("Регистрация обработчиков событий...");
        installLog("  → main::OnProlog → AdminHandler::onProlog", 'success');
        installLog("  → main::OnAdminTabControlBegin → AdminHandler::onTabControlBegin", 'success');
        installLog("  → main::OnAdminListDisplay → AdminHandler::onAdminListDisplay", 'success');
        installLog("  → iblock::OnAfterIBlockElementUpdate → DependencyHandler::onElementUpdate", 'success');
        $moduleClass->installEvents();
        installLog("Обработчики зарегистрированы", 'success');
        
        // Регистрируем модуль ТОЛЬКО после успешного завершения всех шагов
        installLog("Регистрация модуля в системе.");
        $moduleClass->registerModule();
        installLog("Модуль зарегистрирован", 'success');
        
        installLog("═══ УСТАНОВКА ЗАВЕРШЕНА! ═══", 'header');
        
        unset($_SESSION['PROSPEKTWEB_CALC_INSTALL']);
        break;
}

$installData['current_step'] = $currentStep + 1;

?>

<style>
.install-log {
    background: #1e1e1e;
    color: #d4d4d4;
    padding: 20px;
    font-family: 'Consolas', 'Monaco', 'Courier New', monospace;
    font-size: 13px;
    line-height: 1.8;
    border-radius: 4px;
    max-height: 500px;
    overflow-y: auto;
    margin: 20px 0;
}
.install-log .log-info { color: #d4d4d4; }
.install-log .log-info::before { content: '→ '; }
.install-log .log-success { color: #4ec9b0; }
.install-log .log-success::before { content: '✓ '; }
.install-log .log-warning { color: #dcdcaa; }
.install-log .log-warning::before { content: '⚠ '; }
.install-log .log-error { color: #f14c4c; }
.install-log .log-error::before { content: '✗ '; }
.install-log .log-header { color: #569cd6; font-weight: bold; margin-top: 10px; }
.install-log .log-header::before { content: ''; }
.install-buttons { margin-top: 20px; }
.install-buttons .adm-btn-save { margin-right: 10px; }
</style>

<div class="install-log">
    <?php foreach ($installData['log'] as $entry): ?>
    <div class="log-<?= $entry['type'] ?>"><?= htmlspecialcharsbx($entry['message']) ?></div>
    <?php endforeach; ?>
</div>

<?php if (!empty($installData['errors'])): ?>
<div class="adm-info-message adm-info-message-red">
    <strong>Обнаружены ошибки:</strong>
    <ul>
        <?php foreach ($installData['errors'] as $error): ?>
        <li><?= htmlspecialcharsbx($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="install-buttons">
    <?php if ($currentStep < 5): ?>
    <form action="<?= $APPLICATION->GetCurPage() ?>" method="post" style="display: inline;">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
        <input type="hidden" name="id" value="prospektweb.calc">
        <input type="hidden" name="install" value="Y">
        <input type="hidden" name="step" value="3">
        <input type="hidden" name="install_step" value="<?= $currentStep + 1 ?>">
        <input type="submit" value="Продолжить → Шаг <?= $currentStep + 1 ?>" class="adm-btn-save">
    </form>
    <?php else: ?>
    <form action="<?= $APPLICATION->GetCurPage() ?>" method="post" style="display: inline;">
        <?= bitrix_sessid_post() ?>
        <input type="hidden" name="lang" value="<?= LANGUAGE_ID ?>">
        <input type="hidden" name="id" value="prospektweb.calc">
        <input type="hidden" name="install" value="Y">
        <input type="hidden" name="step" value="4">
        <input type="submit" value="Завершить установку" class="adm-btn-save">
    </form>
    <?php endif; ?>
    
    <a href="/bitrix/admin/partner_modules.php?lang=<?= LANGUAGE_ID ?>" class="adm-btn">Отмена</a>
</div>
