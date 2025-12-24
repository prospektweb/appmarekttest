<?php

namespace Prospektweb\Calc\Services;

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Prospektweb\Calc\Calculator\ElementDataService;

/**
 * Обработчик активации панели цен (pricepanel)
 * Загружает данные вариантов материалов и операций при инициализации панели
 */
class PricePanelHandler
{
    private const MODULE_ID = 'prospektweb.calc';

    private ElementDataService $elementDataService;

    public function __construct()
    {
        Loader::includeModule('iblock');
        Loader::includeModule('catalog');
        
        $this->elementDataService = new ElementDataService();
    }

    /**
     * Обработка активации панели цен
     * 
     * @param array $data Данные запроса с calculatorSettingsId, detailId, defaultOperationVariantId, defaultMaterialVariantId
     * @return array Ответ с данными вариантов
     */
    public function handleActivation(array $data): array
    {
        try {
            $calculatorSettingsId = (int)($data['calculatorSettingsId'] ?? 0);
            $detailId = (int)($data['detailId'] ?? 0);
            $defaultOperationVariantId = isset($data['defaultOperationVariantId']) ? (int)$data['defaultOperationVariantId'] : null;
            $defaultMaterialVariantId = isset($data['defaultMaterialVariantId']) ? (int)$data['defaultMaterialVariantId'] : null;

            // Получаем ID инфоблоков для вариантов
            $materialVariantsIblockId = (int)Option::get(self::MODULE_ID, 'IBLOCK_CALC_MATERIAL_VARIANTS', 0);
            $operationVariantsIblockId = (int)Option::get(self::MODULE_ID, 'IBLOCK_CALC_OPERATION_VARIANTS', 0);

            $result = [
                'status' => 'ok',
                'operationVariant' => null,
                'materialVariant' => null,
            ];

            // Загружаем данные варианта операции, если указан
            if ($defaultOperationVariantId && $operationVariantsIblockId > 0) {
                $operationData = $this->elementDataService->loadSingleElement(
                    $operationVariantsIblockId,
                    $defaultOperationVariantId,
                    null,
                    true // includeParent
                );
                
                if ($operationData) {
                    $result['operationVariant'] = $operationData;
                }
            }

            // Загружаем данные варианта материала, если указан
            if ($defaultMaterialVariantId && $materialVariantsIblockId > 0) {
                $materialData = $this->elementDataService->loadSingleElement(
                    $materialVariantsIblockId,
                    $defaultMaterialVariantId,
                    null,
                    true // includeParent - это ключевой параметр для подтягивания данных родителя
                );
                
                if ($materialData) {
                    $result['materialVariant'] = $materialData;
                }
            }

            return $result;

        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }
}
