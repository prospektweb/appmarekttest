<?php
/**
 * API endpoint: Список калькуляторов
 * GET /local/modules/prospektweb.calc/tools/calculators.php
 */

define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('PUBLIC_AJAX_MODE', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Prospektweb\Calc\Calculator\CalculatorRegistry;

global $APPLICATION;
$APPLICATION->RestartBuffer();

global $USER;

header('Content-Type: application/json; charset=UTF-8');

if (!check_bitrix_sessid() || !$USER->IsAdmin()) {
    echo json_encode(['error' => 'access_denied']);
    die();
}

if (!Loader::includeModule('prospektweb.calc')) {
    echo json_encode(['error' => 'module_not_loaded']);
    die();
}

try {
    $groups = CalculatorRegistry::getGroupsForJson();
    $calculators = CalculatorRegistry::getListForJson();

    echo json_encode([
        'groups' => $groups,
        'calculators' => $calculators,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Exception $e) {
    echo json_encode([
        'error' => 'internal_error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}

die();
