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
     * @param bool $force Принудительное создание нового bundle (после подтверждения)
     * @return array
     * @throws \Exception
     */
    public function prepareInitPayload(array $offerIds, string $siteId, bool $force = false): array
    {
        if (empty($offerIds)) {
            throw new \Exception('Список торговых предложений не может быть пустым');
        }

        Loader::includeModule('iblock');
        Loader::includeModule('catalog');

        // Загружаем информацию о ТП
        $selectedOffers = $this->loadOffers($offerIds);
        
        // Анализируем состояние BUNDLE у ТП
        $analysis = $this->analyzeBundles($selectedOffers);
        
        // Если конфликт и не подтверждено — возвращаем данные для попапа
        if ($analysis['scenario'] === 'CONFLICT' && !$force) {
            return [
                'requiresConfirmation' => true,
                'existingBundles' => $analysis['existingBundles'],
                'offersWithBundle' => $analysis['offersWithBundle'],
                'offersWithoutBundle' => $analysis['offersWithoutBundle'],
            ];
        }
        
        // Определяем bundleId
        $bundleId = $analysis['bundleId'];
        
        if ($bundleId === null || $force) {
            // Создаём новый временный bundle
            $bundleHandler = new BundleHandler();
            $bundleId = $bundleHandler->createTemporaryBundle($offerIds);
        }
        
        // Загружаем bundle с данными
        $bundle = $this->loadBundle($bundleId);

        // Собираем контекст
        $context = $this->buildContext($siteId);

        // Собираем ID инфоблоков
        $iblocks = $this->getIblocks();
        $iblocksTypes = $this->getIblockTypes($iblocks);

        // Формируем payload БЕЗ mode!
        return [
            'context' => $context,
            'iblocks' => $iblocks,
            'iblocksTypes' => $iblocksTypes,
            'iblocksTree' => $this->buildIblocksTree(),
            'selectedOffers' => $selectedOffers,
            'priceTypes' => $this->getPriceTypes(),
            'bundle' => $bundle,
        ];
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

            $offers[] = [
                'id' => $offerId,
                'iblockId' => (int)$element['IBLOCK_ID'],
                'name' => $element['NAME'] ?? '',
                'code' => $element['CODE'] ?? null,
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
                // bundleId теперь не нужен в offer, он общий для всех
            ];
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
     * Анализировать состояние BUNDLE у торговых предложений
     * 
     * @param array $offers Массив ТП
     * @return array Результат анализа
     */
    private function analyzeBundles(array $offers): array
    {
        $bundleIds = [];
        $offersWithBundle = [];
        $offersWithoutBundle = [];
        
        foreach ($offers as $offer) {
            $bundleId = $this->extractBundleId($offer);
            
            if ($bundleId !== null && $bundleId > 0) {
                $bundleIds[$bundleId] = $bundleId;
                $offersWithBundle[$offer['id']] = $bundleId;
            } else {
                $offersWithoutBundle[] = $offer['id'];
            }
        }
        
        $uniqueBundleIds = array_values($bundleIds);
        
        // Сценарий A: У всех одинаковый bundle → используем существующий
        if (count($uniqueBundleIds) === 1 && empty($offersWithoutBundle)) {
            return [
                'scenario' => 'EXISTING_BUNDLE',
                'bundleId' => $uniqueBundleIds[0],
                'requiresConfirmation' => false,
            ];
        }
        
        // Сценарий B: Ни у кого нет bundle → создаём новый (без предупреждения)
        if (empty($uniqueBundleIds)) {
            return [
                'scenario' => 'NEW_BUNDLE',
                'bundleId' => null,
                'requiresConfirmation' => false,
            ];
        }
        
        // Сценарий C: Смешанная ситуация → нужно предупреждение
        $bundleHandler = new BundleHandler();
        $bundlesSummary = $bundleHandler->loadBundlesSummary($uniqueBundleIds);
        
        // Добавляем offerIds к каждой сборке
        $existingBundles = [];
        foreach ($bundlesSummary as $id => $info) {
            $info['offerIds'] = array_keys(array_filter($offersWithBundle, fn($bid) => $bid === $id));
            $existingBundles[] = $info;
        }
        
        return [
            'scenario' => 'CONFLICT',
            'bundleId' => null,
            'requiresConfirmation' => true,
            'existingBundles' => $existingBundles,
            'offersWithBundle' => $offersWithBundle,
            'offersWithoutBundle' => $offersWithoutBundle,
        ];
    }

    /**
     * Извлечь bundleId из offer
     * 
     * @param array $offer Данные ТП
     * @return int|null
     */
    private function extractBundleId(array $offer): ?int
    {
        $value = $offer['properties']['BUNDLE']['VALUE'] ?? null;
        
        if ($value === null || $value === false || $value === '' || $value === '0') {
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
            'priceRounding' => (float)Option::get(self::MODULE_ID, 'PRICE_ROUNDING', 1),
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
            'calcBundles' => (int)($moduleIblocks['CALC_BUNDLES'] ?? 0),
            'calcStages' => (int)($moduleIblocks['CALC_STAGES'] ?? 0),
            'calcSettings' => (int)($moduleIblocks['CALC_SETTINGS'] ?? 0),
            'calcCustomFields' => (int)($moduleIblocks['CALC_CUSTOM_FIELDS'] ?? 0),
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
    * Загрузить bundle со всеми данными
    * 
    * @param int $bundleId ID сборки
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

        // Получаем основные поля элемента
        $rsElement = \CIBlockElement:: GetList(
            [],
            ['ID' => $bundleId, 'IBLOCK_ID' => $iblockId],
            false,
            ['nTopCount' => 1],
            ['ID', 'NAME', 'CODE', 'IBLOCK_SECTION_ID']
        );

        $fields = $rsElement->Fetch();
        if (! $fields) {
            return null;
        }

        // Получаем свойства через GetProperty (работает для версии 2)
        $propertiesRaw = $this->loadBundleProperties($iblockId, $bundleId);

        // Парсим JSON-свойство
        $json = [];
        if (! empty($propertiesRaw['JSON'])) {
            $jsonValue = $propertiesRaw['JSON'][0] ?? null;
            if (is_array($jsonValue) && isset($jsonValue['TEXT'])) {
                $decoded = json_decode($jsonValue['TEXT'], true);
                if (is_array($decoded)) {
                    $json = $decoded;
                }
            } elseif (is_string($jsonValue)) {
                $decoded = json_decode($jsonValue, true);
                if (is_array($decoded)) {
                    $json = $decoded;
                }
            }
        }

        // Собираем ID связанных элементов
        $linkedElementIds = $this->collectLinkedElementIdsFromRaw($propertiesRaw);
        
        // Загружаем данные связанных элементов
        $elements = $this->loadBundleElements($linkedElementIds);
        
        // Определяем, временная ли сборка
        $bundleHandler = new BundleHandler();
        $isTemporary = $bundleHandler->isTemporaryBundle($bundleId);

        return [
            'id' => $bundleId,
            'name' => $fields['NAME'] ?? '',
            'code' => $fields['CODE'] ?? '',
            'isTemporary' => $isTemporary,
            'json' => $json,
            'elements' => $elements,
        ];
    }

    /**
    * Загрузить свойства bundle через GetProperty (для инфоблоков версии 2)
    * 
    * @param int $iblockId ID инфоблока
    * @param int $elementId ID элемента
    * @return array Массив [CODE => [values]]
    */
    private function loadBundleProperties(int $iblockId, int $elementId): array
    {
        $propCodes = [
            'JSON',
            'CALC_CONFIG',
            'CALC_SETTINGS',
            'CALC_MATERIALS',
            'CALC_MATERIALS_VARIANTS',
            'CALC_OPERATIONS',
            'CALC_OPERATIONS_VARIANTS',
            'CALC_EQUIPMENT',
            'CALC_DETAILS',
            'CALC_DETAILS_VARIANTS',
        ];
        
        $result = [];
        
        foreach ($propCodes as $code) {
            $result[$code] = [];
            
            $rsProperty = \CIBlockElement::GetProperty(
                $iblockId,
                $elementId,
                [],
                ['CODE' => $code]
            );
            
            while ($arProp = $rsProperty->Fetch()) {
                if ($arProp['VALUE'] !== null && $arProp['VALUE'] !== '') {
                    $result[$code][] = $arProp['VALUE'];
                }
            }
        }
        
        return $result;
    }

    /**
    * Собрать ID связанных элементов из сырых данных свойств
    * 
    * @param array $propertiesRaw Массив [CODE => [values]]
    * @return array
    */
    private function collectLinkedElementIdsFromRaw(array $propertiesRaw): array
    {
        $map = [
            'calcConfig' => 'CALC_CONFIG',
            'calcSettings' => 'CALC_SETTINGS',
            'materials' => 'CALC_MATERIALS',
            'materialsVariants' => 'CALC_MATERIALS_VARIANTS',
            'operations' => 'CALC_OPERATIONS',
            'operationsVariants' => 'CALC_OPERATIONS_VARIANTS',
            'equipment' => 'CALC_EQUIPMENT',
            'details' => 'CALC_DETAILS',
            'detailsVariants' => 'CALC_DETAILS_VARIANTS',
        ];
        
        $result = [];
        
        foreach ($map as $jsKey => $propCode) {
            $values = $propertiesRaw[$propCode] ??  [];
            $result[$jsKey] = array_filter(array_map('intval', $values), fn($id) => $id > 0);
        }
        
        return $result;
    }

    /**
     * Загрузить данные связанных элементов
     * 
     * @param array $linkedIds Массив ID по категориям
     * @return array
     */
    private function loadBundleElements(array $linkedIds): array
    {
        $elementDataService = new ElementDataService();
        $configManager = new ConfigManager();
        
        $iblockMap = [
            'calcConfig' => $configManager->getIblockId('CALC_CONFIG'),
            'calcSettings' => $configManager->getIblockId('CALC_SETTINGS'),
            'materials' => $configManager->getIblockId('CALC_MATERIALS'),
            'materialsVariants' => $configManager->getIblockId('CALC_MATERIALS_VARIANTS'),
            'operations' => $configManager->getIblockId('CALC_OPERATIONS'),
            'operationsVariants' => $configManager->getIblockId('CALC_OPERATIONS_VARIANTS'),
            'equipment' => $configManager->getIblockId('CALC_EQUIPMENT'),
            'details' => $configManager->getIblockId('CALC_DETAILS'),
            'detailsVariants' => $configManager->getIblockId('CALC_DETAILS_VARIANTS'),
        ];
        
        $result = [];
        
        foreach ($linkedIds as $key => $ids) {
            if (empty($ids)) {
                $result[$key] = [];
                continue;
            }
            
            $iblockId = $iblockMap[$key] ?? 0;
            if ($iblockId <= 0) {
                $result[$key] = [];
                continue;
            }
            
            // Определяем, нужен ли родитель (для вариантов)
            $includeParent = in_array($key, ['materialsVariants', 'operationsVariants', 'detailsVariants']);
            
            $payload = $elementDataService->prepareRefreshPayload([
                [
                    'iblockId' => $iblockId,
                    'ids' => $ids,
                    'includeParent' => $includeParent,
                ],
            ]);
            
            $result[$key] = $payload[0]['data'] ?? [];
        }
        
        return $result;
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
