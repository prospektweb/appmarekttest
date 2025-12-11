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
use Prospektweb\Calc\Calculator\SaveHandler;

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

        default:
            sendJsonResponse(['error' => 'Invalid action', 'message' => 'Неизвестное действие'], 400);
    }
} catch (\Exception $e) {
    logError('Exception in calculator_ajax.php: ' . $e->getMessage());
    sendJsonResponse(['error' => 'Server error', 'message' => $e->getMessage()], 500);
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
