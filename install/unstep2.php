<?php
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);

global $APPLICATION;

$deleteData = ($_REQUEST['DELETE_DATA'] ?? '') === 'Y';
$moduleId = 'prospektweb.calc';

// Удаляем обработчики событий
$em = EventManager::getInstance();
$em->unRegisterEventHandler('main', 'OnAdminTabControlBegin', $moduleId);
$em->unRegisterEventHandler('main', 'OnAdminListDisplay', $moduleId);
$em->unRegisterEventHandler('iblock', 'OnAfterIBlockElementUpdate', $moduleId);

// Удаляем файлы
DeleteDirFilesEx('/local/js/prospektweb.calc');
DeleteDirFilesEx('/local/css/prospektweb.calc');

// Удаляем данные если выбрано
if ($deleteData) {
    Loader::includeModule('iblock');
    Loader::includeModule('catalog');

    // Порядок важен: сначала варианты (SKU), потом родительские
    $iblockCodes = [
        'CALC_MATERIALS_VARIANTS',
        'CALC_WORKS_VARIANTS',
        'CALC_DETAILS_VARIANTS',
        'CALC_MATERIALS',
        'CALC_WORKS',
        'CALC_DETAILS',
        'CALC_EQUIPMENT',
        'CALC_CONFIG',
        'CALC_SETTINGS',
    ];

    // Собираем ID инфоблоков один раз
    $iblockIds = [];
    foreach ($iblockCodes as $code) {
        $rsIBlock = \CIBlock::GetList([], ['CODE' => $code]);
        while ($arIBlock = $rsIBlock->Fetch()) {
            $iblockIds[] = (int)$arIBlock['ID'];
        }
    }

    // Шаг 1: Разрываем SKU-связи
    foreach ($iblockIds as $iblockId) {
        \CCatalog::Delete($iblockId);
    }

    // Шаг 2: Удаляем элементы
    foreach ($iblockIds as $iblockId) {
        $rsElements = \CIBlockElement::GetList([], ['IBLOCK_ID' => $iblockId], false, false, ['ID']);
        while ($arElement = $rsElements->Fetch()) {
            \CIBlockElement::Delete($arElement['ID']);
        }
    }

    // Шаг 3: Удаляем разделы
    foreach ($iblockIds as $iblockId) {
        $rsSections = \CIBlockSection::GetList(['DEPTH_LEVEL' => 'DESC'], ['IBLOCK_ID' => $iblockId], false, ['ID']);
        while ($arSection = $rsSections->Fetch()) {
            \CIBlockSection::Delete($arSection['ID']);
        }
    }

    // Шаг 4: Удаляем инфоблоки
    foreach ($iblockIds as $iblockId) {
        \CIBlock::Delete($iblockId);
    }

    // Шаг 5: Удаляем типы инфоблоков
    \CIBlockType::Delete('calculator');
    \CIBlockType::Delete('calculator_catalog');

    // Удаляем настройки
    Option::delete($moduleId);
}

// Снимаем модуль с регистрации
ModuleManager::unRegisterModule($moduleId);

// Очищаем таблицу событий
global $DB;
$DB->Query("DELETE FROM b_module_to_module WHERE TO_MODULE_ID = '" . $DB->ForSql($moduleId) . "'");
?>

<div class="adm-info-message adm-info-message-green">
    <?= Loc::getMessage('PROSPEKTWEB_CALC_UNINSTALL_SUCCESS') ?>
</div>

<div style="margin-top: 20px;">
    <a href="/bitrix/admin/partner_modules.php?lang=<?= LANGUAGE_ID ?>" class="adm-btn-save"><?= Loc::getMessage('PROSPEKTWEB_CALC_BACK_TO_MODULES') ?></a>
</div>
