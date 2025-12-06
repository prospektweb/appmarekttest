<?php
/**
 * API endpoint: Загрузка/сохранение конфигурации
 * GET/POST /local/modules/prospektweb.calc/tools/config.php
 */

define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('PUBLIC_AJAX_MODE', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;
use Prospektweb\Calc\Services\ResultWriter;

global $APPLICATION;
$APPLICATION->RestartBuffer();

global $USER;

header('Content-Type: application/json; charset=UTF-8');

if (!check_bitrix_sessid() || !$USER->IsAdmin()) {
    echo json_encode(['error' => 'access_denied']);
    die();
}

if (!Loader::includeModule('prospektweb.calc') || !Loader::includeModule('iblock')) {
    echo json_encode(['error' => 'modules_not_loaded']);
    die();
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    $configManager = new ConfigManager();

    if ($method === 'GET') {
        // Загрузка конфигурации
        $variantId = (int)($_GET['variant_id'] ?? 0);

        if ($variantId <= 0) {
            echo json_encode(['error' => 'variant_id_required']);
            die();
        }

        $iblockId = $configManager->getIblockId('CALC_CONFIG');

        if ($iblockId <= 0) {
            echo json_encode(['error' => 'config_iblock_not_found', 'config' => null]);
            die();
        }

        // Ищем конфигурацию для этого варианта
        $rsElements = \CIBlockElement::GetList(
            ['ID' => 'DESC'],
            [
                'IBLOCK_ID' => $iblockId,
                'PROPERTY_PRODUCT_ID' => $variantId,
            ],
            false,
            ['nTopCount' => 1],
            ['ID', 'NAME', 'PROPERTY_STATUS', 'PROPERTY_TOTAL_COST', 'PROPERTY_STRUCTURE', 'PROPERTY_LAST_CALC_DATE']
        );

        if ($arElement = $rsElements->Fetch()) {
            $structure = null;
            $structureRaw = $arElement['PROPERTY_STRUCTURE_VALUE'] ?? null;

            if ($structureRaw) {
                if (is_array($structureRaw) && isset($structureRaw['TEXT'])) {
                    $structureRaw = $structureRaw['TEXT'];
                }
                $structure = json_decode($structureRaw, true);
            }

            echo json_encode([
                'config' => [
                    'id' => (int)$arElement['ID'],
                    'variantId' => $variantId,
                    'status' => $arElement['PROPERTY_STATUS_VALUE'] ?? 'draft',
                    'totalCost' => (float)($arElement['PROPERTY_TOTAL_COST_VALUE'] ?? 0),
                    'lastCalcDate' => $arElement['PROPERTY_LAST_CALC_DATE_VALUE'] ?? null,
                    'structure' => $structure,
                ],
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['success' => true, 'config' => null]);
        }
    } elseif ($method === 'POST') {
        // Сохранение конфигурации
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!is_array($data)) {
            echo json_encode(['error' => 'invalid_json']);
            die();
        }

        $variantId = (int)($data['variant_id'] ?? 0);
        $structure = $data['structure'] ?? [];
        $totalCost = (float)($data['total_cost'] ?? 0);
        $usedIds = $data['used_ids'] ?? [];

        if ($variantId <= 0) {
            echo json_encode(['error' => 'variant_id_required']);
            die();
        }

        $resultWriter = new ResultWriter();
        $configId = $resultWriter->saveCalculationConfig($variantId, $structure, $totalCost, $usedIds);

        if ($configId) {
            echo json_encode([
                'success' => true,
                'config_id' => $configId,
            ], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['error' => 'save_failed']);
        }
    } else {
        echo json_encode(['error' => 'method_not_allowed']);
    }
} catch (\Exception $e) {
    echo json_encode([
        'error' => 'internal_error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

die();
