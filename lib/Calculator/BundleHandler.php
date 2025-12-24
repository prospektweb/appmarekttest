<?php

namespace Prospektweb\Calc\Calculator;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Prospektweb\Calc\Config\ConfigManager;

/**
 * Обработчик операций со сборками (bundles)
 */
class BundleHandler
{
    private const MODULE_ID = 'prospektweb.calc';
    
    private ConfigManager $configManager;
    
    public function __construct()
    {
        Loader::includeModule('iblock');
        $this->configManager = new ConfigManager();
    }
    
    /**
     * Создать временный bundle при открытии калькулятора
     * 
     * @param array $offerIds ID торговых предложений
     * @return int ID созданного bundle
     * @throws \Exception
     */
    public function createTemporaryBundle(array $offerIds): int
    {
        // Сначала ротация — удаляем старые если превышен лимит
        $this->rotateTemporaryBundles();
        
        $iblockId = $this->configManager->getIblockId('CALC_BUNDLES');
        $sectionId = (int)Option::get(self::MODULE_ID, 'TEMP_BUNDLES_SECTION_ID', 0);
        
        if ($iblockId <= 0) {
            throw new \Exception('Инфоблок CALC_BUNDLES не настроен');
        }
        
        $el = new \CIBlockElement();
        $bundleId = $el->Add([
            'IBLOCK_ID' => $iblockId,
            'IBLOCK_SECTION_ID' => $sectionId > 0 ? $sectionId : null,
            'NAME' => 'Временная сборка ' . date('Y-m-d H:i:s'),
            'ACTIVE' => 'Y',
            'PROPERTY_VALUES' => [
                'JSON' => ['VALUE' => ['TEXT' => '{}', 'TYPE' => 'HTML']],
            ],
        ]);
        
        if (!$bundleId) {
            throw new \Exception('Ошибка создания временной сборки: ' . $el->LAST_ERROR);
        }
        
        // Привязываем bundle к выбранным ТП
        foreach ($offerIds as $offerId) {
            \CIBlockElement::SetPropertyValuesEx((int)$offerId, false, [
                'BUNDLE' => $bundleId,
            ]);
        }
        
        return (int)$bundleId;
    }
    
    /**
     * Ротация временных сборок — удаляем старые при превышении лимита
     */
    private function rotateTemporaryBundles(): void
    {
        $limit = (int)Option::get(self::MODULE_ID, 'TEMP_BUNDLES_LIMIT', 5);
        $sectionId = (int)Option::get(self::MODULE_ID, 'TEMP_BUNDLES_SECTION_ID', 0);
        
        if ($sectionId <= 0 || $limit <= 0) {
            return;
        }
        
        $iblockId = $this->configManager->getIblockId('CALC_BUNDLES');
        
        if ($iblockId <= 0) {
            return;
        }
        
        // Считаем текущее количество временных сборок
        $rsCount = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'SECTION_ID' => $sectionId],
            false,
            false,
            ['ID']
        );
        $currentCount = $rsCount->SelectedRowsCount();
        
        // Если достигнут лимит — удаляем самые старые
        if ($currentCount >= $limit) {
            $toDelete = $currentCount - $limit + 1;
            
            $rsOldest = \CIBlockElement::GetList(
                ['ID' => 'ASC'], // Самые старые первыми (меньший ID = раньше создан)
                ['IBLOCK_ID' => $iblockId, 'SECTION_ID' => $sectionId],
                false,
                ['nTopCount' => $toDelete],
                ['ID']
            );
            
            while ($arElement = $rsOldest->Fetch()) {
                $this->deleteBundle((int)$arElement['ID']);
            }
        }
    }
    
    /**
     * Сохранить данные bundle (SAVE_BUNDLE_REQUEST)
     * 
     * @param array $payload Данные от React
     * @return array Результат сохранения
     * @throws \Exception
     */
    public function saveBundle(array $payload): array
    {
        $bundleId = (int)($payload['bundleId'] ?? 0);
        
        if ($bundleId <= 0) {
            throw new \Exception('bundleId не указан');
        }
        
        $linkedElements = $payload['linkedElements'] ?? [];
        $json = $payload['json'] ?? [];
        $meta = $payload['meta'] ?? [];
        
        // Формируем свойства для обновления
        $properties = $this->buildPropertyValues($linkedElements);
        
        // JSON с данными UI
        $jsonData = json_encode($json, JSON_UNESCAPED_UNICODE);
        $properties['JSON'] = ['VALUE' => ['TEXT' => $jsonData, 'TYPE' => 'HTML']];
        
        // Обновляем элемент
        $el = new \CIBlockElement();
        $fields = [
            'PROPERTY_VALUES' => $properties,
        ];
        
        if (!empty($meta['name'])) {
            $fields['NAME'] = $meta['name'];
        }
        
        if (!$el->Update($bundleId, $fields)) {
            throw new \Exception('Ошибка сохранения сборки: ' . $el->LAST_ERROR);
        }
        
        return [
            'status' => 'ok',
            'bundleId' => $bundleId,
        ];
    }
    
    /**
     * Финализировать bundle — перенести из временного раздела в корень
     * 
     * @param int $bundleId ID сборки
     * @param string|null $name Новое название (опционально)
     * @return array Результат
     * @throws \Exception
     */
    public function finalizeBundle(int $bundleId, ?string $name = null): array
    {
        $el = new \CIBlockElement();
        
        $fields = [
            'IBLOCK_SECTION_ID' => false, // Перемещаем в корень (без раздела)
        ];
        
        if ($name) {
            $fields['NAME'] = $name;
        }
        
        if (!$el->Update($bundleId, $fields)) {
            throw new \Exception('Ошибка финализации сборки: ' . $el->LAST_ERROR);
        }
        
        return [
            'status' => 'ok',
            'bundleId' => $bundleId,
            'finalized' => true,
        ];
    }
    
    /**
     * Удалить bundle и очистить привязки в ТП
     * 
     * @param int $bundleId ID сборки
     */
    public function deleteBundle(int $bundleId): void
    {
        // Очищаем привязки в ТП
        $skuIblockId = $this->configManager->getSkuIblockId();
        
        if ($skuIblockId > 0) {
            $rsOffers = \CIBlockElement::GetList(
                [],
                ['IBLOCK_ID' => $skuIblockId, 'PROPERTY_BUNDLE' => $bundleId],
                false,
                false,
                ['ID']
            );
            
            while ($arOffer = $rsOffers->Fetch()) {
                \CIBlockElement::SetPropertyValuesEx((int)$arOffer['ID'], false, [
                    'BUNDLE' => false,
                ]);
            }
        }
        
        // Удаляем элемент bundle
        \CIBlockElement::Delete($bundleId);
    }
    
    /**
     * Проверить, является ли bundle временным (находится в разделе временных)
     * 
     * @param int $bundleId ID сборки
     * @return bool
     */
    public function isTemporaryBundle(int $bundleId): bool
    {
        $sectionId = (int)Option::get(self::MODULE_ID, 'TEMP_BUNDLES_SECTION_ID', 0);
        
        if ($sectionId <= 0) {
            return false;
        }
        
        $iblockId = $this->configManager->getIblockId('CALC_BUNDLES');
        
        $rsElement = \CIBlockElement::GetList(
            [],
            ['ID' => $bundleId, 'IBLOCK_ID' => $iblockId],
            false,
            ['nTopCount' => 1],
            ['ID', 'IBLOCK_SECTION_ID']
        );
        
        if ($arElement = $rsElement->Fetch()) {
            return (int)$arElement['IBLOCK_SECTION_ID'] === $sectionId;
        }
        
        return false;
    }
    
    /**
     * Загрузить краткую информацию о сборках (для попапа предупреждения)
     * 
     * @param array $bundleIds ID сборок
     * @return array
     */
    public function loadBundlesSummary(array $bundleIds): array
    {
        if (empty($bundleIds)) {
            return [];
        }
        
        $iblockId = $this->configManager->getIblockId('CALC_BUNDLES');
        $result = [];
        
        $rsElements = \CIBlockElement::GetList(
            [],
            ['ID' => $bundleIds, 'IBLOCK_ID' => $iblockId],
            false,
            false,
            ['ID', 'NAME']
        );
        
        while ($arElement = $rsElements->Fetch()) {
            $result[(int)$arElement['ID']] = [
                'id' => (int)$arElement['ID'],
                'name' => $arElement['NAME'],
            ];
        }
        
        return $result;
    }
    
    /**
     * Собрать массив свойств из linkedElements
     * 
     * @param array $linkedElements Массив связанных элементов
     * @return array
     */
    private function buildPropertyValues(array $linkedElements): array
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
        
        $properties = [];
        
        foreach ($map as $jsKey => $propCode) {
            $ids = $linkedElements[$jsKey] ?? [];
            if (!is_array($ids)) {
                $ids = [$ids];
            }
            $ids = array_filter(array_map('intval', $ids), fn($id) => $id > 0);
            
            // Для множественных свойств Bitrix ожидает массив или false для очистки
            $properties[$propCode] = !empty($ids) ? $ids : false;
        }
        
        return $properties;
    }
}
