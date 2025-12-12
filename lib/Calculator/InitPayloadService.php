<?php

namespace Prospektweb\Calc\Calculator;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Сервис подготовки INIT payload для React-калькулятора
 */
class InitPayloadService
{
    /** @var string ID модуля */
    private const MODULE_ID = 'prospektweb.calc';

    /**
     * Подготовить INIT payload для отправки в iframe
     *
     * @param array $offerIds ID торговых предложений
     * @param string $siteId ID сайта
     * @return array
     * @throws \Exception
     */
    public function prepareInitPayload(array $offerIds, string $siteId): array
    {
        if (empty($offerIds)) {
            throw new \Exception('Список торговых предложений не может быть пустым');
        }

        Loader::includeModule('iblock');
        Loader::includeModule('catalog');

        // Собираем информацию о торговых предложениях
        $selectedOffers = $this->loadOffers($offerIds);

        // Определяем режим работы
        $mode = $this->determineMode($selectedOffers);

        // Собираем контекст
        $context = $this->buildContext($siteId);

        // Собираем ID инфоблоков
        $iblocks = $this->getIblocks();
        $iblocksTypes = $this->getIblockTypes($iblocks);

        $payload = [
            'mode' => $mode,
            'context' => $context,
            'iblocks' => $iblocks,
            'iblocksTypes' => $iblocksTypes,
            'selectedOffers' => $selectedOffers,
        ];

        // Если режим EXISTING_CONFIG - загружаем конфигурацию
        if ($mode === 'EXISTING_CONFIG' && !empty($selectedOffers[0]['configId'])) {
            $config = $this->loadConfiguration($selectedOffers[0]['configId']);
            if ($config) {
                $payload['config'] = $config;
            }
        }

        return $payload;
    }

    /**
     * Загрузить информацию о торговых предложениях
     *
     * @param array $offerIds
     * @return array
     */
    private function loadOffers(array $offerIds): array
    {
        $offers = [];
        $propertyConfigId = Option::get(self::MODULE_ID, 'PROPERTY_CONFIG_ID', 'CONFIG_ID');

        foreach ($offerIds as $offerId) {
            $offerId = (int)$offerId;
            if ($offerId <= 0) {
                continue;
            }

            $elementObject = \CIBlockElement::GetList(
                [],
                ['ID' => $offerId],
                false,
                false,
                ['ID', 'IBLOCK_ID', 'NAME', 'PROPERTY_*']
            )->GetNextElement();

            if (!$elementObject) {
                continue;
            }

            $element = $elementObject->GetFields();
            $propertiesRaw = $elementObject->GetProperties();

            $properties = [];
            $configIdValue = null;

            foreach ($propertiesRaw as $prop) {
                $code = $prop['CODE'] ?: (string)$prop['ID'];
                $value = $prop['MULTIPLE'] === 'Y' ? (array)$prop['VALUE'] : $prop['VALUE'];

                if ($code === $propertyConfigId && $configIdValue === null) {
                    $configIdValue = is_array($value) ? (int)reset($value) : (int)$value;
                }

                $properties[$code] = $value;
            }

            $productData = \CCatalogProduct::GetByID($offerId) ?: [];
            $measureInfo = $this->getMeasureInfo((int)($productData['MEASURE'] ?? 0));
            $measureRatio = $this->getMeasureRatio($offerId);
            $prices = $this->getPrices($offerId);

            $productId = (int)($element['PROPERTY_CML2_LINK_VALUE'] ?? 0);
            if ($productId <= 0) {
                $skuParent = \CCatalogSku::GetProductInfo($offerId);
                if (!empty($skuParent['ID'])) {
                    $productId = (int)$skuParent['ID'];
                }
            }

            // Получаем свойства элемента
            $offer = [
                'id' => $offerId,
                'productId' => $productId,
                'name' => $element['NAME'] ?? '',
                'fields' => [
                    'width' => isset($productData['WIDTH']) ? (float)$productData['WIDTH'] : null,
                    'height' => isset($productData['HEIGHT']) ? (float)$productData['HEIGHT'] : null,
                    'length' => isset($productData['LENGTH']) ? (float)$productData['LENGTH'] : null,
                    'weight' => isset($productData['WEIGHT']) ? (float)$productData['WEIGHT'] : null,
                ],
                'measure' => $measureInfo,
                'measureRatio' => $measureRatio,
                'prices' => $prices,
                'properties' => $properties,
            ];

            // Добавляем configId если есть
            if (!empty($configIdValue)) {
                $offer['configId'] = $configIdValue;
            }

            $offers[] = $offer;
        }

        return $offers;
    }

    /**
     * Получить коэффициент единицы измерения для товара
     */
    private function getMeasureRatio(int $productId): float
    {
        if ($productId <= 0) {
            return 1.0;
        }

        $ratioIterator = \CCatalogMeasureRatio::getList(
            [],
            ['PRODUCT_ID' => $productId]
        );

        if ($ratio = $ratioIterator->Fetch()) {
            return (float)($ratio['RATIO'] ?? 1);
        }

        return 1.0;
    }

    /**
     * Получить информацию о единице измерения
     */
    private function getMeasureInfo(int $measureId): ?array
    {
        if ($measureId <= 0) {
            return null;
        }

        $measureIterator = \CCatalogMeasure::getList(
            ['ID' => 'ASC'],
            ['=ID' => $measureId]
        );

        if ($measure = $measureIterator->Fetch()) {
            return [
                'id' => (int)$measure['ID'],
                'code' => $measure['CODE'] ?? null,
                'symbol' => $measure['SYMBOL'] ?? null,
                'symbolInt' => $measure['SYMBOL_INTL'] ?? null,
                'title' => $measure['MEASURE_TITLE'] ?? null,
            ];
        }

        return null;
    }

    /**
     * Получить цены для торгового предложения
     */
    private function getPrices(int $productId): array
    {
        if ($productId <= 0) {
            return [];
        }

        $prices = [];
        $priceIterator = \CPrice::GetList(
            [],
            ['PRODUCT_ID' => $productId]
        );

        while ($price = $priceIterator->Fetch()) {
            $prices[] = [
                'typeId' => (int)$price['CATALOG_GROUP_ID'],
                'price' => (float)$price['PRICE'],
                'currency' => $price['CURRENCY'] ?? null,
                'quantityFrom' => isset($price['QUANTITY_FROM']) ? (int)$price['QUANTITY_FROM'] : null,
                'quantityTo' => isset($price['QUANTITY_TO']) ? (int)$price['QUANTITY_TO'] : null,
            ];
        }

        return $prices;
    }

    /**
     * Определить режим работы калькулятора
     *
     * @param array $offers
     * @return string 'NEW_CONFIG' | 'EXISTING_CONFIG'
     */
    private function determineMode(array $offers): string
    {
        if (empty($offers)) {
            return 'NEW_CONFIG';
        }

        // Проверяем наличие configId у первого элемента
        $firstConfigId = $offers[0]['configId'] ?? null;
        
        if ($firstConfigId === null) {
            return 'NEW_CONFIG';
        }

        // Проверяем, что у всех одинаковый configId
        foreach ($offers as $offer) {
            $currentConfigId = $offer['configId'] ?? null;
            if ($currentConfigId !== $firstConfigId) {
                return 'NEW_CONFIG';
            }
        }

        return 'EXISTING_CONFIG';
    }

    /**
     * Собрать контекст запроса
     *
     * @param string $siteId
     * @return array
     */
    private function buildContext(string $siteId): array
    {
        global $USER;

        $context = Application::getInstance()->getContext();

        $resolvedSiteId = $context->getSite() ?: (defined('SITE_ID') ? SITE_ID : null);
        if (empty($resolvedSiteId)) {
            $resolvedSiteId = $siteId;
        }

        $languageId = $context->getLanguage() ?: (defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru');

        $siteUrl = $this->buildSiteUrl($context->getRequest()->getHttpHost());

        $userId = '0';
        if (is_object($USER) && method_exists($USER, 'GetID')) {
            $userIdValue = $USER->GetID();
            if ($userIdValue !== null) {
                $userId = (string)$userIdValue;
            }
        }

        return [
            'siteId' => (string)$resolvedSiteId,
            'userId' => $userId,
            'lang' => $languageId,
            'timestamp' => time(),
            'url' => $siteUrl,
        ];
    }

    private function buildSiteUrl(?string $host): string
    {
        if (empty($host)) {
            $host = (string)Option::get('main', 'server_name', '');
        }

        $host = trim((string)$host);

        if ($host === '') {
            return '';
        }

        return sprintf('https://%s/', $host);
    }

    /**
     * Получить ID инфоблоков из настроек
     *
     * @return array
     */
    private function getIblocks(): array
    {
        $configManager = new ConfigManager();
        $moduleIblocks = $configManager->getAllIblockIds();

        $iblocks = [
            'products' => $configManager->getProductIblockId(),
            'offers' => $configManager->getSkuIblockId(),
            'materials' => (int)Option::get(self::MODULE_ID, 'IBLOCK_MATERIALS', 0),
            'operations' => (int)Option::get(self::MODULE_ID, 'IBLOCK_OPERATIONS', 0),
            'equipment' => (int)Option::get(self::MODULE_ID, 'IBLOCK_EQUIPMENT', 0),
            'details' => (int)Option::get(self::MODULE_ID, 'IBLOCK_DETAILS', 0),
            'calculators' => (int)Option::get(self::MODULE_ID, 'IBLOCK_CALCULATORS', 0),
            'configurations' => (int)Option::get(self::MODULE_ID, 'IBLOCK_CONFIGURATIONS', 0),
            'calcConfig' => (int)($moduleIblocks['CALC_CONFIG'] ?? 0),
            'calcSettings' => (int)($moduleIblocks['CALC_SETTINGS'] ?? 0),
            'calcMaterials' => (int)($moduleIblocks['CALC_MATERIALS'] ?? 0),
            'calcMaterialsVariants' => (int)($moduleIblocks['CALC_MATERIALS_VARIANTS'] ?? 0),
            'calcOperations' => (int)($moduleIblocks['CALC_OPERATIONS'] ?? 0),
            'calcOperationsVariants' => (int)($moduleIblocks['CALC_OPERATIONS_VARIANTS'] ?? 0),
            'calcEquipment' => (int)($moduleIblocks['CALC_EQUIPMENT'] ?? 0),
            'calcDetails' => (int)($moduleIblocks['CALC_DETAILS'] ?? 0),
            'calcDetailsVariants' => (int)($moduleIblocks['CALC_DETAILS_VARIANTS'] ?? 0),
        ];

        return array_filter($iblocks, static fn($value) => $value > 0);
    }

    /**
     * Построить карту типов инфоблоков по их ID
     */
    private function getIblockTypes(array $iblocks): array
    {
        $types = [];

        foreach ($iblocks as $iblockId) {
            $id = (int)$iblockId;
            if ($id <= 0) {
                continue;
            }

            $iblock = \CIBlock::GetArrayByID($id);
            $typeId = (string)($iblock['IBLOCK_TYPE_ID'] ?? '');

            if ($typeId === '') {
                continue;
            }

            $types[(string)$id] = $typeId;
        }

        return $types;
    }

    /**
     * Загрузить конфигурацию по ID
     *
     * @param int $configId
     * @return array|null
     */
    private function loadConfiguration(int $configId): ?array
    {
        if ($configId <= 0) {
            return null;
        }

        $iblockId = (int)Option::get(self::MODULE_ID, 'IBLOCK_CONFIGURATIONS', 0);
        if ($iblockId <= 0) {
            return null;
        }

        $element = \CIBlockElement::GetByID($configId)->Fetch();
        if (!$element || (int)$element['IBLOCK_ID'] !== $iblockId) {
            return null;
        }

        // Получаем детальное описание (JSON конфигурации)
        $detailText = $element['DETAIL_TEXT'] ?? '';
        $data = [];
        if (!empty($detailText)) {
            $decoded = json_decode($detailText, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        return [
            'id' => $configId,
            'name' => $element['NAME'] ?? '',
            'data' => $data,
        ];
    }
}
