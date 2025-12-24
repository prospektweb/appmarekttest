<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

/**
 * Обработчик синхронизации вариантов деталей
 */
class SyncVariantsHandler
{
    private const MODULE_ID = 'prospektweb.calc';
    
    private array $stats = [
        'detailsCreated' => 0,
        'detailsUpdated' => 0,
        'configsCreated' => 0,
        'configsDeleted' => 0,
    ];
    
    private array $errors = [];
    
    /**
     * Обработать запрос синхронизации
     */
    public function handle(array $payload): array
    {
        try {
            Loader::includeModule('iblock');
            
            $items = $payload['items'] ?? [];
            $offerIds = $payload['offerIds'] ?? [];
            $deletedConfigIds = $payload['deletedConfigIds'] ?? [];
            $context = $payload['context'] ?? [];
            
            // Удаляем старые конфигурации
            $this->deleteConfigs($deletedConfigIds);
            
            // Обрабатываем элементы рекурсивно
            $processedItems = $this->processItems($items);
            
            return [
                'status' => empty($this->errors) ? 'ok' : 'partial',
                'items' => $processedItems,
                'canCalculate' => $this->checkCanCalculate($processedItems, $offerIds),
                'stats' => $this->stats,
                'errors' => $this->errors,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'items' => [],
                'canCalculate' => false,
                'stats' => $this->stats,
                'errors' => [['message' => $e->getMessage()]],
            ];
        }
    }
    
    /**
     * Обработать элементы (детали и скрепления)
     */
    private function processItems(array $items): array
    {
        $result = [];
        
        foreach ($items as $item) {
            $processed = $this->processItem($item);
            if ($processed) {
                $result[] = $processed;
            }
        }
        
        return $result;
    }
    
    /**
     * Обработать один элемент
     */
    private function processItem(array $item): ?array
    {
        $detailsIblockId = $this->getIblockId('CALC_DETAILS');
        $configIblockId = $this->getIblockId('CALC_CONFIG');
        
        if ($detailsIblockId <= 0 || $configIblockId <= 0) {
            $this->errors[] = ['itemId' => $item['id'], 'message' => 'Инфоблоки не настроены'];
            return null;
        }
        
        $type = $item['type'] ?? 'detail';
        $bitrixId = $item['bitrixId'] ?? null;
        
        // Создаём или обновляем элемент CALC_DETAILS
        if ($bitrixId) {
            $this->updateDetail($detailsIblockId, $bitrixId, $item);
            $this->stats['detailsUpdated']++;
        } else {
            $bitrixId = $this->createDetail($detailsIblockId, $item);
            if ($bitrixId) {
                $this->stats['detailsCreated']++;
            } else {
                $this->errors[] = ['itemId' => $item['id'], 'message' => 'Не удалось создать деталь'];
                return null;
            }
        }
        
        // Обрабатываем калькуляторы
        $processedCalculators = [];
        
        if ($type === 'detail') {
            // Для деталей: создаём конфигурации и связываем через CALC_CONFIG
            $configIds = [];
            foreach ($item['calculators'] ?? [] as $calc) {
                $configId = $this->createOrUpdateConfig($configIblockId, $calc, $item['name']);
                if ($configId) {
                    $configIds[] = $configId;
                    $processedCalculators[] = [
                        'id' => $calc['id'],
                        'configId' => $configId,
                    ];
                    $this->stats['configsCreated']++;
                }
            }
            
            // Обновляем связи детали
            $this->updateDetailBindings($detailsIblockId, $bitrixId, [
                'CALC_CONFIG' => $configIds,
                'CALC_CONFIG_BINDINGS' => [],
                'CALC_CONFIG_BINDINGS_FINISHING' => [],
                'DETAILS' => [],
            ]);
            
        } else {
            // Для скреплений
            $bindingConfigIds = [];
            $finishingConfigIds = [];
            
            foreach ($item['bindingCalculators'] ?? [] as $calc) {
                $configId = $this->createOrUpdateConfig($configIblockId, $calc, $item['name'] . ' (скрепление)');
                if ($configId) {
                    $bindingConfigIds[] = $configId;
                    $processedCalculators[] = ['id' => $calc['id'], 'configId' => $configId];
                    $this->stats['configsCreated']++;
                }
            }
            
            foreach ($item['finishingCalculators'] ?? [] as $calc) {
                $configId = $this->createOrUpdateConfig($configIblockId, $calc, $item['name'] . ' (финиш)');
                if ($configId) {
                    $finishingConfigIds[] = $configId;
                    $processedCalculators[] = ['id' => $calc['id'], 'configId' => $configId];
                    $this->stats['configsCreated']++;
                }
            }
            
            // Находим bitrixId вложенных элементов
            $childBitrixIds = $this->resolveChildIds($item['childIds'] ?? []);
            
            // Обновляем связи скрепления
            $this->updateDetailBindings($detailsIblockId, $bitrixId, [
                'CALC_CONFIG' => [],
                'CALC_CONFIG_BINDINGS' => $bindingConfigIds,
                'CALC_CONFIG_BINDINGS_FINISHING' => $finishingConfigIds,
                'DETAILS' => $childBitrixIds,
            ]);
        }
        
        return [
            'id' => $item['id'],
            'bitrixId' => $bitrixId,
            'type' => $type,
            'calculators' => $processedCalculators,
        ];
    }
    
    /**
     * Создать элемент CALC_DETAILS
     */
    private function createDetail(int $iblockId, array $item): ?int
    {
        $el = new \CIBlockElement();
        
        $typeValue = $item['type'] === 'binding' ? 'BINDING' : 'DETAIL';
        
        $fields = [
            'IBLOCK_ID' => $iblockId,
            'NAME' => $item['name'] ?? 'Без названия',
            'ACTIVE' => 'Y',
            'PROPERTY_VALUES' => [
                'TYPE' => $typeValue,
            ],
        ];
        
        $id = $el->Add($fields);
        
        return $id ? (int)$id : null;
    }
    
    /**
     * Обновить элемент CALC_DETAILS
     */
    private function updateDetail(int $iblockId, int $elementId, array $item): bool
    {
        $el = new \CIBlockElement();
        
        $typeValue = $item['type'] === 'binding' ? 'BINDING' : 'DETAIL';
        
        return $el->Update($elementId, [
            'NAME' => $item['name'] ?? 'Без названия',
            'PROPERTY_VALUES' => [
                'TYPE' => $typeValue,
            ],
        ]);
    }
    
    /**
     * Обновить связи детали
     */
    private function updateDetailBindings(int $iblockId, int $elementId, array $bindings): void
    {
        foreach ($bindings as $propCode => $values) {
            \CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [$propCode => $values]);
        }
    }
    
    /**
     * Создать или обновить конфигурацию калькулятора
     */
    private function createOrUpdateConfig(int $iblockId, array $calc, string $detailName): ?int
    {
        $configId = $calc['configId'] ?? null;
        $el = new \CIBlockElement();
        
        $name = sprintf('Конфиг: %s / Этап', $detailName);
        
        $properties = [
            'CALCULATOR_SETTINGS' => $calc['calculatorCode'] ?? null,
            'OPERATION_VARIANT' => $calc['operationVariantId'] ?? null,
            'MATERIAL_VARIANT' => $calc['materialVariantId'] ?? null,
            'EQUIPMENT' => $calc['equipmentId'] ?? null,
            'QUANTITY_OPERATION_VARIANT' => $calc['operationQuantity'] ?? 1,
            'QUANTITY_MATERIAL_VARIANT' => $calc['materialQuantity'] ?? 1,
        ];
        
        // Сохраняем OTHER_OPTIONS как JSON
        if (!empty($calc['otherOptions'])) {
            $properties['OTHER_OPTIONS'] = json_encode($calc['otherOptions'], JSON_UNESCAPED_UNICODE);
        }
        
        // Удаляем null значения
        $properties = array_filter($properties, fn($v) => $v !== null);
        
        if ($configId) {
            // Обновляем существующую конфигурацию
            $el->Update($configId, [
                'NAME' => $name,
                'PROPERTY_VALUES' => $properties,
            ]);
            return $configId;
        } else {
            // Создаём новую
            $fields = [
                'IBLOCK_ID' => $iblockId,
                'NAME' => $name,
                'ACTIVE' => 'Y',
                'PROPERTY_VALUES' => $properties,
            ];
            
            $id = $el->Add($fields);
            return $id ? (int)$id : null;
        }
    }
    
    /**
     * Удалить конфигурации
     */
    private function deleteConfigs(array $configIds): void
    {
        if (empty($configIds)) return;
        
        foreach ($configIds as $id) {
            if (\CIBlockElement::Delete($id)) {
                $this->stats['configsDeleted']++;
            }
        }
    }
    
    /**
     * Преобразовать React ID в Bitrix ID
     */
    private function resolveChildIds(array $childIds): array
    {
        // TODO: Реализовать маппинг React ID → Bitrix ID
        // Пока возвращаем пустой массив
        return [];
    }
    
    /**
     * Проверить можно ли запускать расчёт
     */
    private function checkCanCalculate(array $items, array $offerIds): bool
    {
        // Пока просто проверяем что есть элементы и ТП
        return !empty($items) && !empty($offerIds);
    }
    
    /**
     * Получить ID инфоблока
     */
    private function getIblockId(string $code): int
    {
        return (int)Option::get(self::MODULE_ID, 'IBLOCK_' . $code, 0);
    }
}
