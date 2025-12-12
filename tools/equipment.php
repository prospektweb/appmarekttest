<?php
/**
 * API endpoint: Оборудование для операции
 * GET /local/modules/prospektweb.calc/tools/equipment.php?operation_id=XXX
 */

define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('PUBLIC_AJAX_MODE', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;
use Prospektweb\Calc\Services\EntityLoader;

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

$operationId = (int)($_GET['operation_id'] ?? 0);

if ($operationId <= 0) {
    echo json_encode(['error' => 'operation_id_required']);
    die();
}

try {
    $configManager = new ConfigManager();
    $entityLoader = new EntityLoader();

    // Загружаем операцию и её оборудование
    $operationsIblockId = $configManager->getIblockId('CALC_OPERATIONS');
    $equipmentIblockId = $configManager->getIblockId('CALC_EQUIPMENT');

    $operations = $entityLoader->loadElements($operationsIblockId, [$operationId]);

    if (empty($operations[$operationId])) {
        echo json_encode(['success' => false, 'error' => 'operation_not_found', 'equipment' => []]);
        die();
    }

    $operation = $operations[$operationId];
    $equipmentIds = $operation['PROPERTIES']['EQUIPMENTS']['VALUE'] ?? [];

    if (!is_array($equipmentIds)) {
        $equipmentIds = [$equipmentIds];
    }

    $equipmentIds = $entityLoader->filterIds($equipmentIds);

    if (empty($equipmentIds)) {
        echo json_encode(['equipment' => []]);
        die();
    }

    $equipment = $entityLoader->loadElements($equipmentIblockId, $equipmentIds);

    $result = [];
    foreach ($equipment as $id => $item) {
        $result[] = [
            'id' => $id,
            'name' => $item['FIELDS']['NAME'] ?? '',
            'active' => ($item['FIELDS']['ACTIVE'] ?? '') === 'Y',
            'startCost' => (float)($item['PROPERTIES']['START_COST']['VALUE'] ?? 0),
            'maxWidth' => (float)($item['PROPERTIES']['MAX_WIDTH']['VALUE'] ?? 0),
            'maxLength' => (float)($item['PROPERTIES']['MAX_LENGTH']['VALUE'] ?? 0),
            'fields' => $item['PROPERTIES']['FIELDS']['VALUE'] ?? '',
        ];
    }

    echo json_encode([
        'equipment' => $result,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Exception $e) {
    echo json_encode([
        'error' => 'internal_error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

die();
