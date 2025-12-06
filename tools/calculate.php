<?php
/**
 * API endpoint: Запуск расчёта
 * POST /local/modules/prospektweb.calc/tools/calculate.php
 */

define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('PUBLIC_AJAX_MODE', true);

require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Prospektweb\Calc\Calculator\CalculatorRegistry;
use Prospektweb\Calc\Services\ResultWriter;
use Prospektweb\Calc\Services\DependencyTracker;

global $APPLICATION;
$APPLICATION->RestartBuffer();

global $USER;

header('Content-Type: application/json; charset=UTF-8');

if (!check_bitrix_sessid() || !$USER->IsAdmin()) {
    echo json_encode(['error' => 'access_denied']);
    die();
}

if (!Loader::includeModule('prospektweb.calc') || !Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
    echo json_encode(['error' => 'modules_not_loaded']);
    die();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'method_not_allowed']);
    die();
}

try {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!is_array($data)) {
        echo json_encode(['error' => 'invalid_json']);
        die();
    }

    $action = $data['action'] ?? 'test'; // test или full
    $variantIds = $data['variantIds'] ?? [];
    $structure = $data['structure'] ?? [];

    if (empty($variantIds)) {
        echo json_encode(['error' => 'variant_ids_required']);
        die();
    }

    // Фильтруем ID
    $variantIds = array_values(array_unique(array_filter(array_map('intval', $variantIds))));

    if (empty($variantIds)) {
        echo json_encode(['error' => 'invalid_variant_ids']);
        die();
    }

    $results = [];
    $errors = [];

    // Извлекаем цепочку калькуляторов из структуры
    $calculatorChain = $structure['calculators'] ?? [];

    if (empty($calculatorChain)) {
        echo json_encode(['error' => 'no_calculators_in_structure']);
        die();
    }

    $resultWriter = new ResultWriter();
    $dependencyTracker = new DependencyTracker();

    foreach ($variantIds as $variantId) {
        $variantResult = [
            'variantId' => $variantId,
            'priceComponents' => [],
            'totalCost' => 0,
            'errors' => [],
        ];

        $ctx = [
            'offerId' => $variantId,
            'priceComponents' => [],
            'totalCost' => 0,
        ];

        // Выполняем калькуляторы по цепочке
        $chainLength = count($calculatorChain);

        foreach ($calculatorChain as $index => $calcConfig) {
            $calcCode = $calcConfig['code'] ?? '';
            $calcOptions = $calcConfig['options'] ?? [];

            $calculator = CalculatorRegistry::getByCode($calcCode);

            if (!$calculator) {
                $variantResult['errors'][] = "Калькулятор {$calcCode} не найден";
                continue;
            }

            $isLastStep = ($index === $chainLength - 1);
            $ctx['isLastStep'] = $isLastStep;

            try {
                $calcResult = $calculator->calculate($ctx, $calcOptions);

                if (is_array($calcResult)) {
                    if (!empty($calcResult['priceComponent'])) {
                        $ctx['priceComponents'][] = $calcResult['priceComponent'];
                        $variantResult['priceComponents'][] = $calcResult['priceComponent'];
                        $ctx['totalCost'] += (float)($calcResult['priceComponent']['cost'] ?? 0);
                    }

                    if (!empty($calcResult['errors'])) {
                        $variantResult['errors'] = array_merge($variantResult['errors'], $calcResult['errors']);
                    }
                }
            } catch (\Exception $e) {
                $variantResult['errors'][] = "Ошибка в калькуляторе {$calcCode}: " . $e->getMessage();
            }
        }

        $variantResult['totalCost'] = $ctx['totalCost'];

        // Если это полный расчёт (не тестовый), записываем результаты
        if ($action === 'full' && empty($variantResult['errors'])) {
            // Извлекаем использованные ID
            $usedIds = $dependencyTracker->extractUsedIdsFromStructure($structure);

            // Сохраняем конфигурацию
            $resultWriter->saveCalculationConfig(
                $variantId,
                $structure,
                $variantResult['totalCost'],
                $usedIds
            );
        }

        $results[$variantId] = $variantResult;

        if (!empty($variantResult['errors'])) {
            $errors[$variantId] = $variantResult['errors'];
        }
    }

    echo json_encode([
        'success' => empty($errors),
        'action' => $action,
        'results' => $results,
        'errors' => $errors,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Exception $e) {
    // Log full trace server-side, return only generic message to client
    $logFile = $_SERVER['DOCUMENT_ROOT'] . '/local/logs/calculate_error.log';
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0750, true);
    }
    file_put_contents($logFile, date('c') . ' ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND | LOCK_EX);

    echo json_encode([
        'error' => 'internal_error',
        'message' => 'An internal error occurred. Please check the logs.',
    ], JSON_UNESCAPED_UNICODE);
}

die();
