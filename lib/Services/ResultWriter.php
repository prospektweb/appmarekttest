<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Сервис для записи результатов расчёта.
 */
class ResultWriter
{
    /** @var ConfigManager */
    protected ConfigManager $configManager;

    public function __construct()
    {
        $this->configManager = new ConfigManager();
    }

    /**
     * Записывает цену товара.
     *
     * @param int    $productId   ID товара.
     * @param int    $priceTypeId ID типа цены.
     * @param float  $price       Цена.
     * @param string $currency    Валюта.
     * @param int|null $quantityFrom Количество от.
     * @param int|null $quantityTo   Количество до.
     *
     * @return bool
     */
    public function writePrice(
        int $productId,
        int $priceTypeId,
        float $price,
        string $currency = 'RUB',
        ?int $quantityFrom = null,
        ?int $quantityTo = null
    ): bool {
        if (!Loader::includeModule('catalog')) {
            return false;
        }

        if ($productId <= 0 || $priceTypeId <= 0 || $price <= 0) {
            return false;
        }

        // Ищем существующую цену
        $filter = [
            'PRODUCT_ID' => $productId,
            'CATALOG_GROUP_ID' => $priceTypeId,
        ];

        if ($quantityFrom !== null) {
            $filter['QUANTITY_FROM'] = $quantityFrom;
        }
        if ($quantityTo !== null) {
            $filter['QUANTITY_TO'] = $quantityTo;
        }

        $priceRes = \CPrice::GetList([], $filter);

        if ($arPrice = $priceRes->Fetch()) {
            // Обновляем существующую цену
            return (bool)\CPrice::Update($arPrice['ID'], [
                'PRICE' => $price,
                'CURRENCY' => $currency,
            ]);
        } else {
            // Создаём новую цену
            $params = [
                'PRODUCT_ID' => $productId,
                'CATALOG_GROUP_ID' => $priceTypeId,
                'PRICE' => $price,
                'CURRENCY' => $currency,
            ];

            if ($quantityFrom !== null) {
                $params['QUANTITY_FROM'] = $quantityFrom;
            }
            if ($quantityTo !== null) {
                $params['QUANTITY_TO'] = $quantityTo;
            }

            return (bool)\CPrice::Add($params);
        }
    }

    /**
     * Записывает диапазоны цен для товара.
     *
     * @param int    $productId   ID товара.
     * @param int    $priceTypeId ID типа цены.
     * @param array  $ranges      Массив диапазонов [{from, to, value}].
     * @param string $currency    Валюта.
     *
     * @return bool
     */
    public function writePriceRanges(
        int $productId,
        int $priceTypeId,
        array $ranges,
        string $currency = 'RUB'
    ): bool {
        if (!Loader::includeModule('catalog')) {
            return false;
        }

        if ($productId <= 0 || $priceTypeId <= 0 || empty($ranges)) {
            return false;
        }

        // Удаляем существующие цены для этого типа
        $this->deletePrices($productId, $priceTypeId);

        // Добавляем новые цены
        $success = true;

        foreach ($ranges as $range) {
            if (!isset($range['value'])) {
                continue;
            }

            $params = [
                'PRODUCT_ID' => $productId,
                'CATALOG_GROUP_ID' => $priceTypeId,
                'PRICE' => (float)$range['value'],
                'CURRENCY' => $currency,
                'QUANTITY_FROM' => $range['from'] ?? false,
                'QUANTITY_TO' => $range['to'] ?? false,
            ];

            $result = \CPrice::Add($params);
            if (!$result) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Удаляет цены товара для указанного типа.
     *
     * @param int $productId   ID товара.
     * @param int $priceTypeId ID типа цены.
     *
     * @return bool
     */
    public function deletePrices(int $productId, int $priceTypeId): bool
    {
        if (!Loader::includeModule('catalog')) {
            return false;
        }

        $priceRes = \CPrice::GetList(
            [],
            [
                'PRODUCT_ID' => $productId,
                'CATALOG_GROUP_ID' => $priceTypeId,
            ]
        );

        while ($row = $priceRes->Fetch()) {
            if (isset($row['ID'])) {
                \CPrice::Delete((int)$row['ID']);
            }
        }

        return true;
    }

    /**
     * Обновляет закупочную цену товара.
     *
     * @param int    $productId ID товара.
     * @param float  $price     Цена.
     * @param string $currency  Валюта.
     *
     * @return bool
     */
    public function updatePurchasingPrice(int $productId, float $price, string $currency = 'RUB'): bool
    {
        if (!Loader::includeModule('catalog')) {
            return false;
        }

        if ($productId <= 0 || $price <= 0) {
            return false;
        }

        return (bool)\CCatalogProduct::Update($productId, [
            'PURCHASING_PRICE' => $price,
            'PURCHASING_CURRENCY' => $currency,
        ]);
    }

    /**
     * Обновляет физические параметры товара.
     *
     * @param int   $productId ID товара.
     * @param array $params    Параметры (WIDTH, LENGTH, HEIGHT, WEIGHT, MEASURE).
     *
     * @return bool
     */
    public function updateProductParams(int $productId, array $params): bool
    {
        if (!Loader::includeModule('catalog')) {
            return false;
        }

        if ($productId <= 0 || empty($params)) {
            return false;
        }

        // Фильтруем допустимые поля
        $allowedFields = ['WIDTH', 'LENGTH', 'HEIGHT', 'WEIGHT', 'MEASURE'];
        $fields = [];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $params) && $params[$field] !== null) {
                $fields[$field] = $params[$field];
            }
        }

        if (empty($fields)) {
            return true;
        }

        return (bool)\CCatalogProduct::Update($productId, $fields);
    }

    /**
     * Сохраняет конфигурацию расчёта в инфоблок CALC_CONFIG.
     *
     * @param int    $productId ID товара.
     * @param array  $structure Структура расчёта.
     * @param float  $totalCost Итоговая себестоимость.
     * @param array  $usedIds   Использованные ID [materials, works, equipment, details].
     *
     * @return int|bool ID элемента или false.
     */
    public function saveCalculationConfig(
        int $productId,
        array $structure,
        float $totalCost,
        array $usedIds = []
    ) {
        if (!Loader::includeModule('iblock')) {
            return false;
        }

        $iblockId = $this->configManager->getIblockId('CALC_CONFIG');
        if ($iblockId <= 0) {
            return false;
        }

        // Ищем существующую конфигурацию для этого товара
        $rsElements = \CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => $iblockId,
                'PROPERTY_PRODUCT_ID' => $productId,
            ],
            false,
            ['nTopCount' => 1],
            ['ID']
        );

        $existingId = null;
        if ($arElement = $rsElements->Fetch()) {
            $existingId = (int)$arElement['ID'];
        }

        $fields = [
            'IBLOCK_ID' => $iblockId,
            'NAME' => 'Калькуляция для товара ' . $productId,
            'ACTIVE' => 'Y',
        ];

        $properties = [
            'PRODUCT_ID' => $productId,
            'STATUS' => 'active',
            'LAST_CALC_DATE' => date('d.m.Y H:i:s'),
            'TOTAL_COST' => $totalCost,
            'STRUCTURE' => ['VALUE' => ['TEXT' => json_encode($structure), 'TYPE' => 'html']],
        ];

        if (!empty($usedIds['materials'])) {
            $properties['USED_MATERIALS'] = $usedIds['materials'];
        }
        if (!empty($usedIds['works'])) {
            $properties['USED_WORKS'] = $usedIds['works'];
        }
        if (!empty($usedIds['equipment'])) {
            $properties['USED_EQUIPMENT'] = $usedIds['equipment'];
        }
        if (!empty($usedIds['details'])) {
            $properties['USED_DETAILS'] = $usedIds['details'];
        }

        $fields['PROPERTY_VALUES'] = $properties;

        $el = new \CIBlockElement();

        if ($existingId) {
            $el->Update($existingId, $fields);
            return $existingId;
        } else {
            return $el->Add($fields);
        }
    }
}
