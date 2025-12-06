<?php

namespace Prospektweb\Calc\Calculator;

use Prospektweb\Calc\Services\EntityLoader;
use Prospektweb\Calc\Services\ValidationService;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Абстрактный базовый класс для всех калькуляторов.
 */
abstract class BaseCalculator implements CalculatorInterface
{
    /** @var EntityLoader Загрузчик сущностей */
    protected EntityLoader $entityLoader;

    /** @var ValidationService Сервис валидации */
    protected ValidationService $validationService;

    /** @var ConfigManager Менеджер конфигурации */
    protected ConfigManager $configManager;

    /** @var bool Флаг выполненного логирования */
    protected bool $logged = false;

    /** @var int[] ID торговых предложений, для которых выполнено логирование */
    protected array $loggedOfferIds = [];

    /**
     * Конструктор базового калькулятора.
     */
    public function __construct()
    {
        $this->entityLoader = new EntityLoader();
        $this->validationService = new ValidationService();
        $this->configManager = new ConfigManager();
    }

    /**
     * {@inheritdoc}
     */
    abstract public function getCode(): string;

    /**
     * {@inheritdoc}
     */
    abstract public function getTitle(): string;

    /**
     * {@inheritdoc}
     */
    abstract public function getOptionsSpec(): array;

    /**
     * {@inheritdoc}
     */
    abstract public function calculate(array $ctx, array $options);

    /**
     * {@inheritdoc}
     */
    public function getGroup(): string
    {
        return 'common';
    }

    /**
     * {@inheritdoc}
     */
    public function supportsChain(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsFinalization(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function canChangePrice(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function isSystem(): bool
    {
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function canBeFirst(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function requiresBefore(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getFieldsConfig(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getExtraOptions(): array
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getPositionConstraints(): array
    {
        return [
            'canBeFirst' => $this->canBeFirst(),
            'requiresBefore' => $this->requiresBefore(),
        ];
    }

    /**
     * Нормализует опции с учётом значений по умолчанию.
     *
     * @param array $options Входные опции.
     *
     * @return array Нормализованные опции.
     */
    protected function normalizeOptionsWithDefaults(array $options): array
    {
        $prepared = [];
        $spec = $this->getOptionsSpec();

        foreach ($spec as $option) {
            $code = $option['code'];
            $default = $option['default'] ?? null;
            $value = array_key_exists($code, $options) ? $options[$code] : $default;

            if (in_array($option['type'], ['checkbox', 'group_checkbox'], true)) {
                $prepared[$code] = ($value === 'Y' || $value === true || $value === '1') ? 'Y' : 'N';
                continue;
            }

            $prepared[$code] = $value;
        }

        foreach ($options as $code => $value) {
            if (!array_key_exists($code, $prepared)) {
                $prepared[$code] = $value;
            }
        }

        return $prepared;
    }

    /**
     * Фильтрует массив ID, оставляя только положительные уникальные целые числа.
     *
     * @param array $ids Массив ID для фильтрации.
     *
     * @return int[] Отфильтрованный массив ID.
     */
    protected function filterIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));
    }

    /**
     * Парсит строку ID в массив.
     *
     * @param mixed $raw Строка или массив ID.
     *
     * @return int[] Массив ID.
     */
    protected function parseIds($raw): array
    {
        if (is_array($raw)) {
            $raw = implode(',', $raw);
        }

        if (!is_string($raw) || $raw === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $raw));

        return array_values(array_unique(array_filter(array_map('intval', $parts), static function (int $id): bool {
            return $id > 0;
        })));
    }

    /**
     * Извлекает числовое значение из данных элемента.
     *
     * @param array  $data Массив данных.
     * @param string $key  Ключ поля.
     *
     * @return float|null Числовое значение или null.
     */
    protected function extractNumberField(array $data, string $key): ?float
    {
        // Проверяем в свойствах
        if (isset($data['PROPERTIES'][$key]['VALUE'])) {
            $value = $data['PROPERTIES'][$key]['VALUE'];
            if (is_numeric($value)) {
                return (float)$value;
            }
        }

        // Проверяем в CATALOG.PRODUCT
        if (isset($data['CATALOG']['PRODUCT'][$key])) {
            $value = $data['CATALOG']['PRODUCT'][$key];
            if (is_numeric($value)) {
                return (float)$value;
            }
        }

        // Проверяем в полях
        if (isset($data['FIELDS'][$key])) {
            $value = $data['FIELDS'][$key];
            if (is_numeric($value)) {
                return (float)$value;
            }
        }

        return null;
    }

    /**
     * Формирует HTML-ссылку на элемент.
     *
     * @param array $fields Поля элемента.
     *
     * @return string HTML-ссылка.
     */
    protected function buildElementLink(array $fields): string
    {
        return $this->validationService->buildElementLink($fields);
    }

    /**
     * Сбрасывает флаг логирования при изменении списка ID.
     *
     * @param int[] $offerIds Текущий список ID.
     */
    protected function resetLoggedIfNeeded(array $offerIds): void
    {
        if ($this->logged && $offerIds !== $this->loggedOfferIds) {
            $this->logged = false;
        }
    }

    /**
     * Устанавливает флаг завершённого логирования.
     *
     * @param int[] $offerIds Список ID, для которых выполнено логирование.
     */
    protected function markAsLogged(array $offerIds): void
    {
        $this->loggedOfferIds = $offerIds;
        $this->logged = true;
    }

    /**
     * Записывает лог расчёта.
     *
     * @param string $label Метка лога.
     * @param mixed  $data  Данные для записи.
     */
    protected function log(string $label, $data = null): void
    {
        $logDir = $_SERVER['DOCUMENT_ROOT'] . '/local/logs';
        $logFile = $logDir . '/calculator_' . $this->getCode() . '.log';

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $str = date('c') . ' [' . $label . "]\n";
        if ($data !== null) {
            $str .= print_r($data, true) . "\n";
        }
        $str .= "-----------------------------\n";

        file_put_contents($logFile, $str, FILE_APPEND | LOCK_EX);
    }
}
