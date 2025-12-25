<?php
/**
 * Административная страница для редактирования дополнительных полей калькуляторов
 * Этот файл автоматически вызывается Битриксом при редактировании элементов 
 * инфоблока CALC_CUSTOM_FIELDS через параметр EDIT_FILE_AFTER
 */

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php');

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

// Проверка авторизации
global $USER, $APPLICATION;
if (!$USER->IsAuthorized()) {
    $APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));
    exit;
}

// Проверка прав доступа
if (!$USER->CanDoOperation('edit_catalog')) {
    $APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));
    exit;
}

// Загрузка модулей
if (!Loader::includeModule('iblock')) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    ShowError('Модуль Инфоблоков не установлен');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    die();
}

if (!Loader::includeModule('prospektweb.calc')) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    ShowError('Модуль prospektweb.calc не установлен');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    die();
}

// Получение параметров
$iblockId = (int)($_REQUEST['IBLOCK_ID'] ?? 0);
$elementId = (int)($_REQUEST['ID'] ?? 0);

if ($iblockId <= 0) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    ShowError('Не указан ID инфоблока');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    die();
}

// Проверяем, что это действительно инфоблок CALC_CUSTOM_FIELDS
$rsIBlock = CIBlock::GetList([], ['ID' => $iblockId, 'CODE' => 'CALC_CUSTOM_FIELDS']);
if (!$rsIBlock->Fetch()) {
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');
    ShowError('Неверный инфоблок');
    require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
    die();
}

// Заголовок страницы
$pageTitle = $elementId > 0 
    ? 'Редактирование дополнительного поля' 
    : 'Создание дополнительного поля';
$APPLICATION->SetTitle($pageTitle);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php');

// Подключение компонента
$APPLICATION->IncludeComponent(
    'prospektweb:calc.custom_field.edit',
    '',
    [
        'IBLOCK_ID' => $iblockId,
        'ELEMENT_ID' => $elementId,
    ],
    false
);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php');
