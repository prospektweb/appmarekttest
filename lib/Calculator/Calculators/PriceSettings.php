<?php

namespace Prospektweb\Calc\Calculator\Calculators;

use Prospektweb\Calc\Calculator\BaseCalculator;

/**
 * Калькулятор настройки цен.
 *
 * Системный калькулятор, который автоматически добавляется в цепочку,
 * если есть хотя бы один калькулятор с canChangePrice = true.
 */
class PriceSettings extends BaseCalculator
{
    /** @var float Minimum price for marketing adjustment */
    protected const MARKETING_PRICE_MIN = 100;

    /** @var float Marketing price suffix (e.g. 990 instead of 1000) */
    protected const MARKETING_PRICE_SUFFIX = 10;

    /**
     * {@inheritdoc}
     */
    public function getCode(): string
    {
        return 'price_settings';
    }

    /**
     * {@inheritdoc}
     */
    public function getTitle(): string
    {
        return 'НАСТРОЙКА ЦЕН';
    }

    /**
     * {@inheritdoc}
     */
    public function getGroup(): string
    {
        return 'price';
    }

    /**
     * {@inheritdoc}
     */
    public function supportsChain(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsFinalization(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function canChangePrice(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function isSystem(): bool
    {
        return true;
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
    public function getOptionsSpec(): array
    {
        return [
            [
                'code' => 'PRICE_MARKUPS',
                'label' => 'Наценки по типам цен',
                'type' => 'price_markups',
                'priceTypes' => $this->getPriceTypeItems(),
                'default' => $this->getDefaultMarkupConfig(),
            ],
            [
                'code' => 'MARKETING_PRICE',
                'label' => 'Маркетинговое снижение цены',
                'type' => 'checkbox',
                'default' => 'N',
            ],
        ];
    }

    /**
     * Список типов цен для фронта.
     *
     * @return array
     */
    protected function getPriceTypeItems(): array
    {
        $items = [];

        if (!\Bitrix\Main\Loader::includeModule('catalog')) {
            return $items;
        }

        $priceTypes = \CCatalogGroup::GetListArray();

        foreach ($priceTypes as $type) {
            $items[] = [
                'value' => (int)$type['ID'],
                'label' => $type['NAME'] ?? ('ID ' . $type['ID']),
            ];
        }

        return $items;
    }

    /**
     * Дефолтная конфигурация наценок для всех типов цен.
     *
     * @return array
     */
    protected function getDefaultMarkupConfig(): array
    {
        $result = [];

        foreach ($this->getPriceTypeItems() as $item) {
            $result[] = [
                'ID' => isset($item['value']) ? (int)$item['value'] : null,
                'TYPE' => 'quantity',
                'RANGES' => [
                    [
                        'from' => 0,
                        'to' => null,
                        'value' => 0,
                    ],
                ],
            ];
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function calculate(array $ctx, array $options)
    {
        $offerId = $ctx['offerId'] ?? 0;
        $normalizedOptions = $this->normalizeOptionsWithDefaults($options);

        $markupConfig = $this->parseMarkupOptions($normalizedOptions['PRICE_MARKUPS'] ?? null);
        $useMarketingPrice = ($normalizedOptions['MARKETING_PRICE'] ?? 'N') === 'Y';

        // Получаем себестоимость из контекста
        $totalCost = $ctx['totalCost'] ?? 0;

        // Рассчитываем цены по диапазонам для всех типов цен
        $pricesByType = $this->calculateMarkupPrices($totalCost, $markupConfig, $useMarketingPrice);

        $this->log('calculate', [
            'offerId' => $offerId,
            'totalCost' => $totalCost,
            'pricesByType' => $pricesByType,
        ]);

        return [
            'priceComponent' => [
                'label' => 'Настройка цен',
                'cost' => 0,
                'pricesByType' => $pricesByType,
            ],
            'success' => true,
            'finalized' => true,
        ];
    }

    /**
     * Парсит опции наценок.
     *
     * @param mixed $raw Входные данные.
     *
     * @return array
     */
    protected function parseMarkupOptions($raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $raw = $decoded;
            }
        }

        if (!is_array($raw)) {
            return $this->getDefaultMarkupConfig();
        }

        $result = [];

        foreach ($raw as $entry) {
            $result[] = [
                'ID' => isset($entry['ID']) ? (int)$entry['ID'] : null,
                'TYPE' => ($entry['TYPE'] ?? 'quantity') === 'cost' ? 'cost' : 'quantity',
                'RANGES' => is_array($entry['RANGES'] ?? null) ? $entry['RANGES'] : [],
            ];
        }

        return $result;
    }

    /**
     * Рассчитывает цены с наценками для всех типов цен.
     *
     * @param float $purchasingPrice   Закупочная цена.
     * @param array $config            Конфигурация наценок.
     * @param bool  $useMarketingPrice Использовать маркетинговое снижение.
     *
     * @return array
     */
    protected function calculateMarkupPrices(float $purchasingPrice, array $config, bool $useMarketingPrice): array
    {
        $result = [];

        foreach ($config as $entry) {
            $priceTypeId = $entry['ID'] ?? null;
            if ($priceTypeId === null) {
                continue;
            }

            $type = $entry['TYPE'] ?? 'quantity';
            $ranges = $entry['RANGES'] ?? [];
            $prepared = [];

            foreach ($ranges as $range) {
                $from = $range['from'] ?? null;
                $to = $range['to'] ?? null;
                $value = isset($range['value']) ? (float)$range['value'] : null;

                if ($value === null) {
                    continue;
                }

                $rawPrice = $purchasingPrice * (1 + $value / 100);

                // Базовое округление
                $price = $this->roundToTen($rawPrice);

                // Маркетинговое снижение
                if ($useMarketingPrice) {
                    $price = $this->applyMarketingPrice($price);
                }

                $prepared[] = [
                    'from' => $from,
                    'to' => $to,
                    'value' => $price,
                ];
            }

            $result[(int)$priceTypeId] = [
                'type' => $type,
                'ranges' => $prepared,
            ];
        }

        return $result;
    }

    /**
     * Округление до десятков.
     *
     * @param float $price Цена.
     *
     * @return float
     */
    protected function roundToTen(float $price): float
    {
        return ceil($price / 10) * 10;
    }

    /**
     * Применение маркетингового снижения (цены типа 990, 1990 и т.д.).
     *
     * @param float $price Price to transform (should be > 0).
     *
     * @return float Marketing price with "charm" ending.
     */
    protected function applyMarketingPrice(float $price): float
    {
        if ($price < self::MARKETING_PRICE_MIN) {
            return $price;
        }

        // Find the order of magnitude (e.g., 2 for 100s, 3 for 1000s)
        $order = (int)floor(log10($price));
        $base = pow(10, $order);

        // Round up to nearest multiple of base and subtract suffix for charm pricing
        $rounded = ceil($price / $base) * $base;
        return $rounded - self::MARKETING_PRICE_SUFFIX;
    }
}
