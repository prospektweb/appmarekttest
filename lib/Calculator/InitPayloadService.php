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
            'priceTypes' => $this->getPriceTypes(),
        ];

        // Если режим EXISTING_BUNDLE - загружаем сборку
        if ($mode === 'EXISTING_BUNDLE' && !empty($selectedOffers[0]['bundleId'])) {
            $bundle = $this->loadBundle($selectedOffers[0]['bundleId']);
            if ($bundle) {
                $payload['bundle'] = $bundle;
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
                ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'PROPERTY_*']
            )->GetNextElement();

            if (!$elementObject) {
                continue;
            }

            $element = $elementObject->GetFields();
            $propertiesRaw = $elementObject->GetProperties();

            $properties = [];

            foreach ($propertiesRaw as $prop) {
                $code = $prop['CODE'] ?: (string)$prop['ID'];
                $properties[$code] = $prop;
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
                'code' => $element['CODE'] ?? null,
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

            // Добавляем bundleId если есть
            $bundleId = $this->extractBundleId($offer);
            if ($bundleId !== null) {
                $offer['bundleId'] = $bundleId;
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
     * @return string 'NEW_BUNDLE' | 'EXISTING_BUNDLE'
     */
    private function determineMode(array $offers): string
    {
        if (empty($offers)) {
            return 'NEW_BUNDLE';
        }

        $firstBundleId = $this->extractBundleId($offers[0]);
        
        if ($firstBundleId === null || $firstBundleId <= 0) {
            return 'NEW_BUNDLE';
        }

        foreach ($offers as $offer) {
            $currentBundleId = $this->extractBundleId($offer);
            if ($currentBundleId !== $firstBundleId) {
                return 'NEW_BUNDLE';
            }
        }

        return 'EXISTING_BUNDLE';
    }

    /**
     * Извлечь bundleId из данных ТП
     *
     * @param array $offer
     * @return int|null
     */
    private function extractBundleId(array $offer): ?int
    {
        $value = $offer['properties']['BUNDLE']['VALUE'] ?? null;
        
        if ($value === null || $value === false || $value === '') {
            return null;
        }
        
        $intValue = (int)$value;
        return $intValue > 0 ? $intValue : null;
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
            'menuLinks' => $this->buildMenuLinks($siteUrl, $languageId),
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
     * Загрузить сборку по ID
     *
     * @param int $bundleId
     * @return array|null
     */
    private function loadBundle(int $bundleId): ?array
    {
        if ($bundleId <= 0) {
            return null;
        }

        $configManager = new ConfigManager();
        $iblockId = $configManager->getIblockId('CALC_BUNDLES');
        
        if ($iblockId <= 0) {
            return null;
        }

        $elementObject = \CIBlockElement::GetList(
            [],
            ['ID' => $bundleId, 'IBLOCK_ID' => $iblockId],
            false,
            false,
            ['ID', 'NAME', 'CODE']
        )->GetNextElement();

        if (!$elementObject) {
            return null;
        }

        $fields = $elementObject->GetFields();
        $propertiesRaw = $elementObject->GetProperties();

        // Парсим JSON-свойство (тип HTML)
        $jsonRaw = $propertiesRaw['JSON']['~VALUE']['TEXT'] ?? '';
        $structure = [];
        if (!empty($jsonRaw)) {
            $decoded = json_decode($jsonRaw, true);
            if (is_array($decoded)) {
                $structure = $decoded;
            }
        }

        // Собираем ID связанных элементов по свойствам
        $linkedElementIds = $this->collectLinkedElementIds($propertiesRaw);
        
        // Загружаем данные связанных элементов через ElementDataService
        $elements = $this->loadBundleElements($linkedElementIds);

        return [
            'id' => $bundleId,
            'name' => $fields['NAME'] ?? '',
            'code' => $fields['CODE'] ?? null,
            'structure' => $structure,
            'elements' => $elements,
        ];
    }

    /**
     * Собрать ID связанных элементов из свойств сборки
     *
     * @param array $propertiesRaw
     * @return array Массив с ключами по типам связанных элементов
     */
    private function collectLinkedElementIds(array $propertiesRaw): array
    {
        $linkedIds = [];

        $propertyMap = [
            'CALC_CONFIG' => 'calcConfig',
            'CALC_SETTINGS' => 'calcSettings',
            'CALC_MATERIALS' => 'materials',
            'CALC_MATERIALS_VARIANTS' => 'materialsVariants',
            'CALC_OPERATIONS' => 'operations',
            'CALC_OPERATIONS_VARIANTS' => 'operationsVariants',
            'CALC_EQUIPMENT' => 'equipment',
            'CALC_DETAILS' => 'details',
            'CALC_DETAILS_VARIANTS' => 'detailsVariants',
        ];

        foreach ($propertyMap as $propertyCode => $key) {
            $values = $propertiesRaw[$propertyCode]['VALUE'] ?? null;
            
            if ($values === null || $values === false || $values === '') {
                $linkedIds[$key] = [];
                continue;
            }

            // Преобразуем в массив, если это не массив
            if (!is_array($values)) {
                $values = [$values];
            }

            // Фильтруем и преобразуем в int
            $linkedIds[$key] = array_values(array_filter(array_map('intval', $values), function($id) {
                return $id > 0;
            }));
        }

        return $linkedIds;
    }

    /**
     * Загрузить связанные элементы через ElementDataService
     *
     * @param array $linkedIds
     * @return array
     */
    private function loadBundleElements(array $linkedIds): array
    {
        $elementDataService = new ElementDataService();
        $elements = [];

        foreach ($linkedIds as $key => $ids) {
            if (empty($ids)) {
                $elements[$key] = [];
                continue;
            }

            // Определяем, нужно ли включать данные родителя (для вариантов)
            $includeParent = in_array($key, ['materialsVariants', 'operationsVariants', 'detailsVariants'], true);

            // Формируем запрос для prepareRefreshPayload
            $request = [
                'ids' => $ids,
                'includeParent' => $includeParent,
            ];

            $result = $elementDataService->prepareRefreshPayload([$request]);
            $elements[$key] = !empty($result[0]['data']) ? $result[0]['data'] : [];
        }

        return $elements;
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

        // CALC_SETTINGS
        if (!empty($iblocks['calcSettings'])) {
            $trees['calcSettings'] = $this->buildIblockTree($iblocks['calcSettings']);
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

        while ($fields = $res->Fetch()) {
            $elements[] = [
                'type' => 'element',
                'id' => (int)$fields['ID'],
                'name' => $fields['NAME'] ?? '',
                'code' => $fields['CODE'] ?? '',
                'iblockId' => (int)$fields['IBLOCK_ID'],
                'sectionId' => !empty($fields['IBLOCK_SECTION_ID']) ? (int)$fields['IBLOCK_SECTION_ID'] : 0,
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

        while ($fields = $res->Fetch()) {
            $parentId = !empty($fields['PROPERTY_CML2_LINK_VALUE'])
                ? (int)$fields['PROPERTY_CML2_LINK_VALUE']
                : 0;

            $variants[] = [
                'type' => 'child',
                'id' => (int)$fields['ID'],
                'name' => $fields['NAME'] ?? '',
                'code' => $fields['CODE'] ?? '',
                'iblockId' => $variantsIblockId,
                'parentId' => $parentId,
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

    /**
     * Построить массив ссылок для меню
     *
     * @param string $siteUrl URL сайта
     * @param string $lang Язык интерфейса
     * @return array
     */
    private function buildMenuLinks(string $siteUrl, string $lang): array
    {
        return [
            [
                'name' => 'Типы цен',
                'url' => "{$siteUrl}/bitrix/admin/cat_group_admin.php?lang={$lang}",
                'target' => '_blank',
            ],
            [
                'name' => 'Единицы измерения',
                'url' => "{$siteUrl}/bitrix/admin/cat_measure_list.php?lang={$lang}",
                'target' => '_blank',
            ],
        ];
    }

    /**
     * Получить список типов цен из каталога Bitrix
     *
     * @return array
     */
    private function getPriceTypes(): array
    {
        $priceTypes = [];

        // Проверяем, что модуль catalog загружен
        if (!Loader::includeModule('catalog')) {
            return $priceTypes;
        }

        try {
            $result = \CCatalogGroup::GetListArray();
            
            if (is_array($result)) {
                foreach ($result as $type) {
                    $priceTypes[] = [
                        'id' => (int)$type['ID'],
                        'name' => $type['NAME'] ?? '',
                        'base' => ($type['BASE'] ?? 'N') === 'Y',
                        'sort' => (int)($type['SORT'] ?? 100),
                    ];
                }
            }
        } catch (\Exception $e) {
            // В случае ошибки возвращаем пустой массив
            return [];
        }

        return $priceTypes;
    }
}
