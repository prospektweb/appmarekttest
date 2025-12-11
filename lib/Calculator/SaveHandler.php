<?php

namespace Prospektweb\Calc\Calculator;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Application;

/**
 * Обработчик сохранения данных из React-калькулятора
 */
class SaveHandler
{
    /** @var string ID модуля */
    private const MODULE_ID = 'prospektweb.calc';

    /** @var string Путь к лог-файлу */
    private const LOG_FILE = '/local/logs/prospektweb.calc.log';

    /** @var bool Кэш состояния логирования */
    private bool $loggingEnabled;

    public function __construct()
    {
        $this->loggingEnabled = Option::get(self::MODULE_ID, 'LOGGING_ENABLED', 'N') === 'Y';
    }

    /**
     * Обработать запрос на сохранение
     *
     * @param array $payload
     * @return array
     */
    public function handleSaveRequest(array $payload): array
    {
        try {
            Loader::includeModule('iblock');
            Loader::includeModule('catalog');

            // Валидация payload
            $this->validatePayload($payload);

            $mode = $payload['mode'] ?? '';
            $configuration = $payload['configuration'] ?? [];
            $offerUpdates = $payload['offerUpdates'] ?? [];

            $db = Application::getConnection();
            $db->startTransaction();

            try {
                // Обработка конфигурации
                $configId = $this->saveConfiguration($mode, $configuration);

                // Обновление торговых предложений
                $result = $this->updateOffers($offerUpdates, $configId);

                $db->commitTransaction();

                return [
                    'status' => $result['hasErrors'] ? 'partial' : 'ok',
                    'configId' => $configId,
                    'successOffers' => $result['successOffers'],
                    'errors' => $result['errors'],
                    'message' => $result['hasErrors'] 
                        ? 'Данные частично сохранены' 
                        : 'Данные успешно сохранены',
                ];
            } catch (\Exception $e) {
                $db->rollbackTransaction();
                throw $e;
            }
        } catch (\Exception $e) {
            $this->logError('SaveHandler error: ' . $e->getMessage());
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'errors' => [],
            ];
        }
    }

    /**
     * Валидировать входящий payload
     *
     * @param array $payload
     * @throws \Exception
     */
    private function validatePayload(array $payload): void
    {
        if (empty($payload['mode']) || !in_array($payload['mode'], ['NEW_CONFIG', 'EXISTING_CONFIG'])) {
            throw new \Exception('Некорректный режим сохранения');
        }

        if (empty($payload['configuration'])) {
            throw new \Exception('Отсутствуют данные конфигурации');
        }

        if (empty($payload['offerUpdates']) || !is_array($payload['offerUpdates'])) {
            throw new \Exception('Отсутствуют данные для обновления торговых предложений');
        }
    }

    /**
     * Сохранить конфигурацию
     *
     * @param string $mode
     * @param array $configuration
     * @return int ID конфигурации
     * @throws \Exception
     */
    private function saveConfiguration(string $mode, array $configuration): int
    {
        $iblockId = (int)Option::get(self::MODULE_ID, 'IBLOCK_CONFIGURATIONS', 0);
        if ($iblockId <= 0) {
            throw new \Exception('Инфоблок конфигураций не настроен');
        }

        $el = new \CIBlockElement();

        if ($mode === 'NEW_CONFIG') {
            // Создаём новую конфигурацию
            $fields = [
                'IBLOCK_ID' => $iblockId,
                'NAME' => $configuration['name'] ?? 'Конфигурация ' . date('Y-m-d H:i:s'),
                'ACTIVE' => 'Y',
                'DETAIL_TEXT' => json_encode($configuration['data'] ?? [], JSON_UNESCAPED_UNICODE),
                'DETAIL_TEXT_TYPE' => 'text',
            ];

            $configId = $el->Add($fields);
            if (!$configId) {
                throw new \Exception('Ошибка создания конфигурации: ' . $el->LAST_ERROR);
            }

            $this->logInfo('Created new configuration: ' . $configId);
            return (int)$configId;
        } else {
            // Обновляем существующую конфигурацию
            $configId = (int)($configuration['id'] ?? 0);
            if ($configId <= 0) {
                throw new \Exception('ID конфигурации не указан для режима EXISTING_CONFIG');
            }

            $fields = [
                'NAME' => $configuration['name'] ?? 'Конфигурация',
                'DETAIL_TEXT' => json_encode($configuration['data'] ?? [], JSON_UNESCAPED_UNICODE),
                'DETAIL_TEXT_TYPE' => 'text',
            ];

            if (!$el->Update($configId, $fields)) {
                throw new \Exception('Ошибка обновления конфигурации: ' . $el->LAST_ERROR);
            }

            $this->logInfo('Updated configuration: ' . $configId);
            return $configId;
        }
    }

    /**
     * Обновить торговые предложения
     *
     * @param array $offerUpdates
     * @param int $configId
     * @return array
     */
    private function updateOffers(array $offerUpdates, int $configId): array
    {
        $successOffers = [];
        $errors = [];
        $propertyConfigId = Option::get(self::MODULE_ID, 'PROPERTY_CONFIG_ID', 'CONFIG_ID');

        foreach ($offerUpdates as $update) {
            $offerId = (int)($update['id'] ?? 0);
            if ($offerId <= 0) {
                $errors[] = [
                    'offerId' => $offerId,
                    'message' => 'Некорректный ID торгового предложения',
                    'code' => 'INVALID_ID',
                ];
                continue;
            }

            try {
                // Привязываем конфигурацию к ТП
                \CIBlockElement::SetPropertyValuesEx($offerId, false, [
                    $propertyConfigId => $configId,
                ]);

                // Обновляем свойства
                if (!empty($update['properties']) && is_array($update['properties'])) {
                    foreach ($update['properties'] as $propCode => $propValue) {
                        \CIBlockElement::SetPropertyValuesEx($offerId, false, [
                            $propCode => $propValue,
                        ]);
                    }
                }

                // Обновляем поля элемента (габариты, вес)
                $fields = [];
                if (isset($update['fields']['width'])) {
                    $fields['WIDTH'] = (float)$update['fields']['width'];
                }
                if (isset($update['fields']['height'])) {
                    $fields['HEIGHT'] = (float)$update['fields']['height'];
                }
                if (isset($update['fields']['length'])) {
                    $fields['LENGTH'] = (float)$update['fields']['length'];
                }
                if (isset($update['fields']['weight'])) {
                    $fields['WEIGHT'] = (float)$update['fields']['weight'];
                }

                if (!empty($fields)) {
                    $el = new \CIBlockElement();
                    if (!$el->Update($offerId, $fields)) {
                        throw new \Exception('Ошибка обновления полей: ' . $el->LAST_ERROR);
                    }
                }

                // Обновляем цены
                if (!empty($update['prices']) && is_array($update['prices'])) {
                    $this->updatePrices($offerId, $update['prices']);
                }

                $successOffers[] = $offerId;
                $this->logInfo('Successfully updated offer: ' . $offerId);
            } catch (\Exception $e) {
                $errors[] = [
                    'offerId' => $offerId,
                    'message' => $e->getMessage(),
                    'code' => 'UPDATE_ERROR',
                ];
                $this->logError('Error updating offer ' . $offerId . ': ' . $e->getMessage());
            }
        }

        return [
            'successOffers' => $successOffers,
            'errors' => $errors,
            'hasErrors' => !empty($errors),
        ];
    }

    /**
     * Обновить цены торгового предложения
     *
     * @param int $offerId
     * @param array $prices
     */
    private function updatePrices(int $offerId, array $prices): void
    {
        foreach ($prices as $priceData) {
            $priceTypeId = (int)($priceData['typeId'] ?? 0);
            $price = (float)($priceData['price'] ?? 0);
            $currency = $priceData['currency'] ?? 'RUB';

            if ($priceTypeId <= 0 || $price < 0) {
                continue;
            }

            \CPrice::SetBasePrice($offerId, $price, $currency, $priceTypeId);
        }
    }

    /**
     * Получить путь к лог-файлу
     *
     * @return string
     */
    private function getLogFilePath(): string
    {
        return $_SERVER['DOCUMENT_ROOT'] . self::LOG_FILE;
    }

    /**
     * Логирование информации
     *
     * @param string $message
     */
    private function logInfo(string $message): void
    {
        if (!$this->loggingEnabled) {
            return;
        }

        $logFile = $this->getLogFilePath();
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] INFO: {$message}\n", FILE_APPEND);
    }

    /**
     * Логирование ошибок
     *
     * @param string $message
     */
    private function logError(string $message): void
    {
        if (!$this->loggingEnabled) {
            return;
        }

        $logFile = $this->getLogFilePath();
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] ERROR: {$message}\n", FILE_APPEND);
    }
}
