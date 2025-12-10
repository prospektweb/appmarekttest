<?php
/**
 * AJAX API endpoint для калькулятора
 * Обрабатывает запросы на получение конфигурации, данных вариантов и сохранение состояния
 */

define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);

require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

header('Content-Type: application/json; charset=UTF-8');

\Bitrix\Main\Loader::includeModule('prospektweb.calc');
\Bitrix\Main\Loader::includeModule('iblock');

use Prospektweb\Calc\Config\ConfigManager;

$configManager = new ConfigManager();
$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'get_config':
        // Вернуть конфигурацию модуля
        echo json_encode([
            'success' => true,
            'data' => [
                'skuIblockId' => $configManager->getSkuIblockId(),
                'productIblockId' => $configManager->getProductIblockId(),
                'siteUrl' => (CMain::IsHTTPS() ? 'https://' : 'http://') . SITE_SERVER_NAME,
                'adminUrl' => '/bitrix/admin/',
            ]
        ]);
        break;
        
    case 'get_variants':
        // Загрузить данные вариантов по ID
        $ids = array_map('intval', explode(',', $_REQUEST['ids'] ?? ''));
        $skuIblockId = $configManager->getSkuIblockId();
        
        $variants = [];
        if (!empty($ids) && $skuIblockId > 0) {
            $rsElements = \CIBlockElement::GetList(
                ['ID' => 'ASC'],
                ['IBLOCK_ID' => $skuIblockId, 'ID' => $ids],
                false,
                false,
                ['ID', 'NAME']
            );
            while ($arElement = $rsElements->Fetch()) {
                $variants[] = [
                    'id' => (int)$arElement['ID'],
                    'name' => $arElement['NAME'],
                    'editUrl' => '/bitrix/admin/iblock_element_edit.php?IBLOCK_ID=' . $skuIblockId . '&type=catalog&ID=' . $arElement['ID'] . '&lang=' . LANGUAGE_ID,
                ];
            }
        }
        
        echo json_encode(['success' => true, 'data' => ['variants' => $variants]]);
        break;
        
    case 'save_state':
        // Сохранить состояние калькулятора
        $state = json_decode(file_get_contents('php://input'), true);
        $_SESSION['CALCULATOR_STATE'] = $state;
        echo json_encode(['success' => true]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
