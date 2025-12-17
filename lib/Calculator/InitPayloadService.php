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
            'iblocksTree' => $this->buildIblocksTree(),
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

        return sprintf('https://%s', $host);
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

        $desiredOrder = [
            'calcDetails',
            'calcDetailsVariants',
            'calcMaterials',
            'calcMaterialsVariants',
            'calcOperations',
            'calcOperationsVariants',
            'calcEquipment',
        ];

        $orderedIds = [];

        foreach ($desiredOrder as $key) {
            if (!empty($iblocks[$key])) {
                $orderedIds[] = (int)$iblocks[$key];
            }
        }

        foreach ($iblocks as $key => $iblockId) {
            if (in_array($key, $desiredOrder, true)) {
                continue;
            }

            $orderedIds[] = (int)$iblockId;
        }

        foreach ($orderedIds as $iblockId) {
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

    /**
     * Собрать дерево данных всех инфоблоков модуля для MultiLevelSelect
     * 
     * @return array Массив деревьев по ключам инфоблоков
     */
    private function buildIblocksTree(): array
    {
        $iblocks = $this->getIblocks();
        $trees = [];
        
        // Товары с ТП
        if (!empty($iblocks['products'])) {
            $trees['products'] = $this->buildProductsTree(
                $iblocks['products'], 
                $iblocks['offers'] ?? 0
            );
        }
        
        // CALC_SETTINGS
        if (!empty($iblocks['calcSettings'])) {
            $trees['calcSettings'] = $this->buildIblockTree($iblocks['calcSettings']);
        }
        
        // CALC_CONFIG
        if (!empty($iblocks['calcConfig'])) {
            $trees['calcConfig'] = $this->buildIblockTree($iblocks['calcConfig']);
        }
        
        // CALC_EQUIPMENT
        if (!empty($iblocks['calcEquipment'])) {
            $trees['calcEquipment'] = $this->buildIblockTree($iblocks['calcEquipment']);
        }
        
        // CALC_MATERIALS с variants
        if (!empty($iblocks['calcMaterials'])) {
            $trees['calcMaterials'] = $this->buildCatalogTree(
                $iblocks['calcMaterials'],
                $iblocks['calcMaterialsVariants'] ?? 0
            );
        }
        
        // CALC_OPERATIONS с variants
        if (!empty($iblocks['calcOperations'])) {
            $trees['calcOperations'] = $this->buildCatalogTree(
                $iblocks['calcOperations'],
                $iblocks['calcOperationsVariants'] ?? 0
            );
        }
        
        // CALC_DETAILS с variants
        if (!empty($iblocks['calcDetails'])) {
            $trees['calcDetails'] = $this->buildCatalogTree(
                $iblocks['calcDetails'],
                $iblocks['calcDetailsVariants'] ?? 0
            );
        }
        
        // Отдельно variants (если нужны без родителей)
        if (!empty($iblocks['calcMaterialsVariants'])) {
            $trees['calcMaterialsVariants'] = $this->buildIblockTree($iblocks['calcMaterialsVariants']);
        }
        if (!empty($iblocks['calcOperationsVariants'])) {
            $trees['calcOperationsVariants'] = $this->buildIblockTree($iblocks['calcOperationsVariants']);
        }
        if (!empty($iblocks['calcDetailsVariants'])) {
            $trees['calcDetailsVariants'] = $this->buildIblockTree($iblocks['calcDetailsVariants']);
        }
        
        return $trees;
    }

    /**
     * Строит дерево разделов и элементов для одного инфоблока (без дочерних элементов)
     *
     * @param int $iblockId ID инфоблока
     * @return array
     */
    private function buildIblockTree(int $iblockId): array
    {
        if ($iblockId <= 0) {
            return [];
        }

        $sections = $this->getSections($iblockId);
        $elements = $this->getElements($iblockId);

        return $this->assembleTree($sections, $elements);
    }

    /**
     * Строит дерево товаров с торговыми предложениями
     *
     * @param int $productIblockId ID инфоблока товаров
     * @param int $offersIblockId ID инфоблока торговых предложений
     * @return array
     */
    private function buildProductsTree(int $productIblockId, int $offersIblockId): array
    {
        if ($productIblockId <= 0) {
            return [];
        }

        $sections = $this->getSections($productIblockId);
        $elements = $this->getElements($productIblockId);

        // Получаем торговые предложения для товаров
        $productIds = array_column($elements, 'id');
        $offers = [];
        
        if ($offersIblockId > 0 && !empty($productIds)) {
            $offersData = \CCatalogSKU::getOffersList(
                $productIds,
                $productIblockId,
                [],
                ['ID', 'NAME', 'CODE'],
                ['ID', 'NAME', 'CODE']
            );
            
            if (is_array($offersData)) {
                foreach ($offersData as $productId => $productOffers) {
                    $offers[$productId] = [];
                    foreach ($productOffers as $offer) {
                        $offers[$productId][] = [
                            'type' => 'child',
                            'id' => (int)$offer['ID'],
                            'name' => $offer['NAME'] ?? '',
                            'code' => $offer['CODE'] ?? '',
                            'iblockId' => $offersIblockId,
                            'parentId' => $productId,
                            'properties' => [],
                        ];
                    }
                }
            }
        }

        // Добавляем торговые предложения к элементам
        foreach ($elements as &$element) {
            if (!empty($offers[$element['id']])) {
                $element['children'] = $offers[$element['id']];
            }
        }
        unset($element);

        return $this->assembleTree($sections, $elements);
    }

    /**
     * Строит дерево для каталогов со SKU-связью (materials, operations, details)
     *
     * @param int $parentIblockId ID основного инфоблока
     * @param int $variantsIblockId ID инфоблока вариантов
     * @return array
     */
    private function buildCatalogTree(int $parentIblockId, int $variantsIblockId): array
    {
        if ($parentIblockId <= 0) {
            return [];
        }

        $sections = $this->getSections($parentIblockId);
        $elements = $this->getElements($parentIblockId);

        // Получаем варианты для элементов
        $parentIds = array_column($elements, 'id');
        $variants = [];
        
        if ($variantsIblockId > 0 && !empty($parentIds)) {
            $variantsData = $this->getVariants($variantsIblockId, $parentIds);
            
            foreach ($variantsData as $variant) {
                $parentId = $variant['parentId'];
                if (!isset($variants[$parentId])) {
                    $variants[$parentId] = [];
                }
                $variants[$parentId][] = $variant;
            }
        }

        // Добавляем варианты к элементам
        foreach ($elements as &$element) {
            if (!empty($variants[$element['id']])) {
                $element['children'] = $variants[$element['id']];
            }
        }
        unset($element);

        return $this->assembleTree($sections, $elements);
    }

    /**
     * Получает разделы и строит иерархию
     *
     * @param int $iblockId ID инфоблока
     * @return array
     */
    private function getSections(int $iblockId): array
    {
        if ($iblockId <= 0) {
            return [];
        }

        $sections = [];
        $res = \CIBlockSection::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'],
            false,
            ['ID', 'NAME', 'CODE', 'IBLOCK_SECTION_ID', 'IBLOCK_ID', 'SORT', 'DEPTH_LEVEL']
        );

        while ($section = $res->Fetch()) {
            $sections[] = [
                'type' => 'section',
                'id' => (int)$section['ID'],
                'name' => $section['NAME'] ?? '',
                'code' => $section['CODE'] ?? '',
                'iblockId' => (int)$section['IBLOCK_ID'],
                'parentId' => !empty($section['IBLOCK_SECTION_ID']) ? (int)$section['IBLOCK_SECTION_ID'] : null,
                'depth' => (int)($section['DEPTH_LEVEL'] ?? 1),
            ];
        }

        return $sections;
    }

    /**
     * Получает элементы с их свойствами
     *
     * @param int $iblockId ID инфоблока
     * @return array
     */
    private function getElements(int $iblockId): array
    {
        if ($iblockId <= 0) {
            return [];
        }

        $elements = [];
        $res = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'],
            false,
            false,
            ['ID', 'NAME', 'CODE', 'IBLOCK_SECTION_ID', 'IBLOCK_ID']
        );

        while ($elementObject = $res->GetNextElement()) {
            $fields = $elementObject->GetFields();
            $propsRaw = $elementObject->GetProperties();

            $properties = [];
            foreach ($propsRaw as $prop) {
                $code = $prop['CODE'] ?: (string)$prop['ID'];
                $value = $prop['MULTIPLE'] === 'Y' ? (array)$prop['VALUE'] : $prop['VALUE'];
                $properties[$code] = $value;
            }

            $elements[] = [
                'type' => 'element',
                'id' => (int)$fields['ID'],
                'name' => $fields['NAME'] ?? '',
                'code' => $fields['CODE'] ?? '',
                'iblockId' => (int)$fields['IBLOCK_ID'],
                'sectionId' => !empty($fields['IBLOCK_SECTION_ID']) ? (int)$fields['IBLOCK_SECTION_ID'] : 0,
                'properties' => $properties,
            ];
        }

        return $elements;
    }

    /**
     * Получает варианты для списка родительских элементов
     *
     * @param int $variantsIblockId ID инфоблока вариантов
     * @param array $parentIds Массив ID родительских элементов
     * @return array
     */
    private function getVariants(int $variantsIblockId, array $parentIds): array
    {
        if ($variantsIblockId <= 0 || empty($parentIds)) {
            return [];
        }

        $variants = [];
        $res = \CIBlockElement::GetList(
            ['SORT' => 'ASC', 'NAME' => 'ASC'],
            [
                'IBLOCK_ID' => $variantsIblockId,
                'ACTIVE' => 'Y',
                'PROPERTY_CML2_LINK' => $parentIds,
            ],
            false,
            false,
            ['ID', 'NAME', 'CODE', 'IBLOCK_ID', 'PROPERTY_CML2_LINK']
        );

        while ($elementObject = $res->GetNextElement()) {
            $fields = $elementObject->GetFields();
            $propsRaw = $elementObject->GetProperties();

            $properties = [];
            $parentId = 0;

            foreach ($propsRaw as $prop) {
                $code = $prop['CODE'] ?: (string)$prop['ID'];
                $value = $prop['MULTIPLE'] === 'Y' ? (array)$prop['VALUE'] : $prop['VALUE'];
                
                if ($code === 'CML2_LINK') {
                    $parentId = is_array($value) ? (int)reset($value) : (int)$value;
                }
                
                $properties[$code] = $value;
            }

            if ($parentId <= 0 && !empty($fields['PROPERTY_CML2_LINK_VALUE'])) {
                $parentId = (int)$fields['PROPERTY_CML2_LINK_VALUE'];
            }

            $variants[] = [
                'type' => 'child',
                'id' => (int)$fields['ID'],
                'name' => $fields['NAME'] ?? '',
                'code' => $fields['CODE'] ?? '',
                'iblockId' => $variantsIblockId,
                'parentId' => $parentId,
                'properties' => $properties,
            ];
        }

        return $variants;
    }

    /**
     * Собирает дерево из разделов и элементов
     *
     * @param array $sections Массив разделов
     * @param array $elements Массив элементов
     * @return array
     */
    private function assembleTree(array $sections, array $elements): array
    {
        // Распределяем элементы по разделам
        $sectionElements = [];
        $rootElements = [];

        foreach ($elements as $element) {
            $sectionId = $element['sectionId'];
            if ($sectionId > 0) {
                if (!isset($sectionElements[$sectionId])) {
                    $sectionElements[$sectionId] = [];
                }
                $sectionElements[$sectionId][] = $element;
            } else {
                $rootElements[] = $element;
            }
        }

        // Функция для построения дерева рекурсивно
        $buildTree = function ($parentId) use (&$buildTree, &$sections, &$sectionElements) {
            $result = [];

            foreach ($sections as $section) {
                if ($section['parentId'] === $parentId) {
                    $sectionNode = $section;
                    
                    // Добавляем дочерние разделы
                    $children = $buildTree($section['id']);
                    
                    // Добавляем элементы текущего раздела
                    if (!empty($sectionElements[$section['id']])) {
                        foreach ($sectionElements[$section['id']] as $element) {
                            $children[] = $element;
                        }
                    }
                    
                    if (!empty($children)) {
                        $sectionNode['children'] = $children;
                    }
                    
                    $result[] = $sectionNode;
                }
            }

            return $result;
        };

        $tree = $buildTree(null);

        // Добавляем элементы без раздела в конец
        if (!empty($rootElements)) {
            $tree = array_merge($tree, $rootElements);
        }

        return $tree;
    }
}
