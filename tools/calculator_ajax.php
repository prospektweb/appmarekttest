<?php
/**
 * AJAX endpoint для интеграции React-калькулятора с Bitrix
 */

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', false);

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Prospektweb\Calc\Calculator\InitPayloadService;
use Prospektweb\Calc\Calculator\ElementDataService;
use Prospektweb\Calc\Calculator\SaveHandler;
use Prospektweb\Calc\Services\HeaderTabsService;
use Prospektweb\Calc\Services\SyncVariantsHandler;

// Constants
const LOG_FILE = '/local/logs/prospektweb.calc.ajax.log';

// Проверка авторизации
global $USER;
if (!$USER->IsAuthorized()) {
    sendJsonResponse(['error' => 'Unauthorized', 'message' => 'Требуется авторизация'], 401);
}

// Проверка прав доступа
if (!$USER->CanDoOperation('edit_catalog')) {
    sendJsonResponse(['error' => 'Forbidden', 'message' => 'Недостаточно прав'], 403);
}

// CSRF защита
if (!check_bitrix_sessid()) {
    sendJsonResponse(['error' => 'Invalid session', 'message' => 'Неверная сессия'], 403);
}

// Загружаем модуль
if (!Loader::includeModule('prospektweb.calc')) {
    sendJsonResponse(['error' => 'Module error', 'message' => 'Модуль не загружен'], 500);
}

// Получаем данные запроса
$request = Application::getInstance()->getContext()->getRequest();

// Проверяем, является ли это PWRT протокол сообщением
$rawInput = file_get_contents('php://input');
$pwrtMessage = null;
if (!empty($rawInput)) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded) && isset($decoded['protocol']) && $decoded['protocol'] === 'pwrt-v1') {
        $pwrtMessage = $decoded;
    }
}

// Определяем тип запроса
if ($pwrtMessage) {
    // Обработка PWRT протокола
    $messageType = $pwrtMessage['type'] ?? '';
    $requestId = $pwrtMessage['requestId'] ?? '';
    $payload = $pwrtMessage['payload'] ?? [];
    
    logRequest($messageType, $pwrtMessage);
    
    try {
        switch ($messageType) {
            case 'SYNC_VARIANTS_REQUEST':
                $handler = new SyncVariantsHandler();
                $result = $handler->handle($payload);
                
                $response = [
                    'protocol' => 'pwrt-v1',
                    'source' => 'bitrix',
                    'target' => 'prospektweb.calc',
                    'type' => 'SYNC_VARIANTS_RESPONSE',
                    'requestId' => $requestId,
                    'payload' => $result,
                    'timestamp' => time(),
                ];
                
                sendJsonResponse($response);
                break;
            
            default:
                sendJsonResponse([
                    'protocol' => 'pwrt-v1',
                    'source' => 'bitrix',
                    'target' => 'prospektweb.calc',
                    'type' => 'ERROR',
                    'requestId' => $requestId,
                    'payload' => ['error' => 'Unknown message type', 'message' => 'Неизвестный тип сообщения'],
                    'timestamp' => time(),
                ], 400);
        }
    } catch (\Exception $e) {
        logError('Exception in PWRT message handler: ' . $e->getMessage());
        sendJsonResponse([
            'protocol' => 'pwrt-v1',
            'source' => 'bitrix',
            'target' => 'prospektweb.calc',
            'type' => 'ERROR',
            'requestId' => $requestId,
            'payload' => ['error' => 'Server error', 'message' => $e->getMessage()],
            'timestamp' => time(),
        ], 500);
    }
} else {
    // Обработка старых action-based запросов
    $action = $request->get('action') ?? '';
    
    // Логирование запроса
    logRequest($action, $request->toArray());


try {
    switch ($action) {
        case 'getInitData':
            handleGetInitData($request);
            break;

        case 'save':
            handleSave($request);
            break;

        case 'refreshData':
            handleRefreshData($request);
            break;

        case 'headerTabsAdd':
            handleHeaderTabsAdd($request);
            break;

        case 'syncVariants':
            $payload = json_decode($_POST['payload'] ?? '{}', true);
            
            $handler = new \Prospektweb\Calc\Services\SyncVariantsHandler();
            $result = $handler->handle($payload);
            
            echo json_encode([
                'success' => true,
                'data' => $result,
            ]);
            break;

        default:
            sendJsonResponse(['error' => 'Invalid action', 'message' => 'Неизвестное действие'], 400);
    }
} catch (\Exception $e) {
    logError('Exception in calculator_ajax.php: ' . $e->getMessage());
    sendJsonResponse(['error' => 'Server error', 'message' => $e->getMessage()], 500);
}
}

/**
 * Обработка запроса getInitData
 */
function handleGetInitData($request): void
{
    $offerIdsRaw = $request->get('offerIds');
    $siteId = $request->get('siteId') ?? SITE_ID;

    if (empty($offerIdsRaw)) {
        sendJsonResponse(['error' => 'Missing parameter', 'message' => 'Параметр offerIds обязателен'], 400);
    }

    // Парсим offerIds (может быть строка или массив)
    $offerIds = is_array($offerIdsRaw) ? $offerIdsRaw : explode(',', $offerIdsRaw);
    $offerIds = array_map('intval', $offerIds);
    $offerIds = array_filter($offerIds, function($id) { return $id > 0; });

    if (empty($offerIds)) {
        sendJsonResponse(['error' => 'Invalid parameter', 'message' => 'Некорректные ID торговых предложений'], 400);
    }

    try {
        $service = new InitPayloadService();
        $payload = $service->prepareInitPayload($offerIds, $siteId);

        logInfo('GetInitData success for offers: ' . implode(',', $offerIds));
        sendJsonResponse(['success' => true, 'data' => $payload]);
    } catch (\Exception $e) {
        logError('GetInitData error: ' . $e->getMessage());
        sendJsonResponse(['error' => 'Processing error', 'message' => $e->getMessage()], 500);
    }
}

/**
 * Обработка запроса save
 */
function handleSave($request): void
{
    $payloadRaw = $request->get('payload');

    if (empty($payloadRaw)) {
        sendJsonResponse(['error' => 'Missing parameter', 'message' => 'Параметр payload обязателен'], 400);
    }

    // Если payload передан как JSON-строка
    if (is_string($payloadRaw)) {
        $payload = json_decode($payloadRaw, true);
        if (!is_array($payload)) {
            sendJsonResponse(['error' => 'Invalid parameter', 'message' => 'Некорректный формат payload'], 400);
        }
    } else {
        $payload = $payloadRaw;
    }

    try {
        $handler = new SaveHandler();
        $result = $handler->handleSaveRequest($payload);

        logInfo('Save request processed. Status: ' . $result['status']);
        sendJsonResponse(['success' => $result['status'] !== 'error', 'data' => $result]);
    } catch (\Exception $e) {
        logError('Save error: ' . $e->getMessage());
        sendJsonResponse(['error' => 'Processing error', 'message' => $e->getMessage()], 500);
    }
}

/**
 * Обработка запроса refreshData
 */
function handleRefreshData($request): void
{
    $payloadRaw = $request->get('payload');

    if (empty($payloadRaw)) {
        sendJsonResponse(['error' => 'Missing parameter', 'message' => 'Параметр payload обязателен'], 400);
    }

    if (is_string($payloadRaw)) {
        $payload = json_decode($payloadRaw, true);
        if (!is_array($payload)) {
            sendJsonResponse(['error' => 'Invalid parameter', 'message' => 'Некорректный формат payload'], 400);
        }
    } else {
        $payload = $payloadRaw;
    }

    try {
        $service = new ElementDataService();
        $result = $service->prepareRefreshPayload($payload);

        logInfo('RefreshData success for ' . count($payload) . ' groups');
        sendJsonResponse(['success' => true, 'data' => $result]);
    } catch (\Exception $e) {
        logError('RefreshData error: ' . $e->getMessage());
        sendJsonResponse(['error' => 'Processing error', 'message' => $e->getMessage()], 500);
    }
}

/**
 * Обработка запроса headerTabsAdd
 */
function handleHeaderTabsAdd($request): void
{
    $iblockId = (int)($request->get('iblockId') ?? 0);
    $entityType = $request->get('entityType');
    $itemIdsRaw = $request->get('itemIds');

    $itemIds = is_array($itemIdsRaw) ? $itemIdsRaw : explode(',', (string)$itemIdsRaw);
    $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static function (int $id): bool {
        return $id > 0;
    })));

    if (empty($itemIds)) {
        sendJsonResponse(['error' => 'Missing parameter', 'message' => 'Не выбраны элементы'], 400);
    }

    $service = new HeaderTabsService();
    $resolvedEntityType = $entityType ?: $service->resolveEntityTypeByIblockId($iblockId);

    if ($resolvedEntityType === null) {
        sendJsonResponse(['error' => 'Invalid parameter', 'message' => 'Инфоблок не поддерживается в калькуляции'], 400);
    }

    $iblockMap = $service->getHeaderIblockMap();
    $targetIblockId = isset($iblockMap[$resolvedEntityType]) ? (int)$iblockMap[$resolvedEntityType] : $iblockId;

    $items = $service->prepareHeaderItems($resolvedEntityType, $targetIblockId, $itemIds);

    sendJsonResponse([
        'success' => true,
        'data' => [
            'entityType' => $resolvedEntityType,
            'items' => $items,
        ],
    ]);
}

/**
 * Отправить JSON ответ
 */
function sendJsonResponse(array $data, int $statusCode = 200): void
{
    if ($statusCode !== 200) {
        http_response_code($statusCode);
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    die();
}

/**
 * Получить путь к лог-файлу
 */
function getLogFilePath(): string
{
    return $_SERVER['DOCUMENT_ROOT'] . LOG_FILE;
}

/**
 * Логирование запроса
 */
function logRequest(string $action, array $data): void
{
    $loggingEnabled = Option::get('prospektweb.calc', 'LOGGING_ENABLED', 'N') === 'Y';
    if (!$loggingEnabled) {
        return;
    }

    $logFile = getLogFilePath();
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    $message = "[{$timestamp}] REQUEST: action={$action}, data=" . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n";
    file_put_contents($logFile, $message, FILE_APPEND);
}

/**
 * Логирование информации
 */
function logInfo(string $message): void
{
    $loggingEnabled = Option::get('prospektweb.calc', 'LOGGING_ENABLED', 'N') === 'Y';
    if (!$loggingEnabled) {
        return;
    }

    $logFile = getLogFilePath();
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[{$timestamp}] INFO: {$message}\n", FILE_APPEND);
}

/**
 * Логирование ошибок
 */
function logError(string $message): void
{
    $loggingEnabled = Option::get('prospektweb.calc', 'LOGGING_ENABLED', 'N') === 'Y';
    if (!$loggingEnabled) {
        return;
    }

    $logFile = getLogFilePath();
    $logDir = dirname($logFile);
    if (!is_dir($logDir)) {
        mkdir($logDir, 0755, true);
    }

    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[{$timestamp}] ERROR: {$message}\n", FILE_APPEND);
}
