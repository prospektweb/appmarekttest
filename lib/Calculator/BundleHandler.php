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
     * Создать новый preset (постоянный)
     * Вместо временного bundle теперь всегда создаём постоянный preset.
     * 
     * @param array $offerIds ID торговых предложений
     * @param string|null $name Название preset'а (опционально)
     * @return int ID созданного preset'а
     * @throws \Exception
     */
    public function createPreset(array $offerIds, ?string $name = null): int
    {
        $iblockId = $this->configManager->getIblockId('CALC_PRESETS');
        
        if ($iblockId <= 0) {
            throw new \Exception('Инфоблок CALC_PRESETS не настроен');
        }
        
        $el = new \CIBlockElement();
        $presetId = $el->Add([
            'IBLOCK_ID' => $iblockId,
            'NAME' => $name ?: 'Новый пресет ' . date('Y-m-d H:i:s'),
            'ACTIVE' => 'Y',
            'PROPERTY_VALUES' => [
                'JSON' => ['VALUE' => ['TEXT' => '{}', 'TYPE' => 'HTML']],
            ],
        ]);
        
        if (!$presetId) {
            throw new \Exception('Ошибка создания пресета: ' . $el->LAST_ERROR);
        }
        
        // Привязываем preset к выбранным ТП
        foreach ($offerIds as $offerId) {
            \CIBlockElement::SetPropertyValuesEx((int)$offerId, false, [
                'PRESET' => $presetId,
            ]);
        }
        
        return (int)$presetId;
    }
    
    /**
     * Сохранить данные preset (SAVE_PRESET_REQUEST)
     * 
     * @param array $payload Данные от React
     * @return array Результат сохранения
     * @throws \Exception
     */
    public function savePreset(array $payload): array
    {
        $presetId = (int)($payload['presetId'] ?? 0);
        
        if ($presetId <= 0) {
            throw new \Exception('presetId не указан');
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
        
        if (!$el->Update($presetId, $fields)) {
            throw new \Exception('Ошибка сохранения пресета: ' . $el->LAST_ERROR);
        }
        
        return [
            'status' => 'ok',
            'presetId' => $presetId,
        ];
    }
    
    /**
     * Финализировать preset уже не требуется, т.к. создаются только постоянные пресеты.
     * Эта функция теперь может использоваться только для переименования preset'а.
     * 
     * @param int $presetId ID пресета
     * @param string|null $name Новое название (опционально)
     * @return array Результат
     * @throws \Exception
     */
    public function finalizePreset(int $presetId, ?string $name = null): array
    {
        if (!$name) {
            // Если имя не передано, ничего не делаем
            return [
                'status' => 'ok',
                'presetId' => $presetId,
                'finalized' => true,
            ];
        }
        
        $el = new \CIBlockElement();
        
        $fields = [
            'NAME' => $name,
        ];
        
        if (!$el->Update($presetId, $fields)) {
            throw new \Exception('Ошибка переименования пресета: ' . $el->LAST_ERROR);
        }
        
        return [
            'status' => 'ok',
            'presetId' => $presetId,
            'finalized' => true,
        ];
    }
    
    /**
     * Удалить preset и очистить привязки в ТП
     * 
     * @param int $presetId ID пресета
     */
    public function deletePreset(int $presetId): void
    {
        // Очищаем привязки в ТП
        $skuIblockId = $this->configManager->getSkuIblockId();
        
        if ($skuIblockId > 0) {
            $rsOffers = \CIBlockElement::GetList(
                [],
                ['IBLOCK_ID' => $skuIblockId, 'PROPERTY_PRESET' => $presetId],
                false,
                false,
                ['ID']
            );
            
            while ($arOffer = $rsOffers->Fetch()) {
                \CIBlockElement::SetPropertyValuesEx((int)$arOffer['ID'], false, [
                    'PRESET' => false,
                ]);
            }
        }
        
        // Удаляем элемент preset
        \CIBlockElement::Delete($presetId);
    }
    
    /**
     * Загрузить краткую информацию о пресетах (для попапа предупреждения)
     * 
     * @param array $presetIds ID пресетов
     * @return array
     */
    public function loadPresetsSummary(array $presetIds): array
    {
        if (empty($presetIds)) {
            return [];
        }
        
        $iblockId = $this->configManager->getIblockId('CALC_PRESETS');
        $result = [];
        
        $rsElements = \CIBlockElement::GetList(
            [],
            ['ID' => $presetIds, 'IBLOCK_ID' => $iblockId],
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
            'calcConfig' => 'CALC_STAGES',
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
