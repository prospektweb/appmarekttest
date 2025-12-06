<?php

namespace Prospektweb\Calc\Install;

use Bitrix\Main\Loader;

/**
 * Класс для создания инфоблоков модуля.
 */
class IblockCreator
{
    /**
     * Создаёт тип инфоблоков.
     *
     * @param string $id   ID типа.
     * @param string $name Название.
     *
     * @return bool
     */
    public function createIblockType(string $id, string $name): bool
    {
        if (!Loader::includeModule('iblock')) {
            return false;
        }

        $type = \CIBlockType::GetByID($id)->Fetch();
        if ($type) {
            return true;
        }

        $arFields = [
            'ID' => $id,
            'SECTIONS' => 'Y',
            'IN_RSS' => 'N',
            'SORT' => 500,
            'LANG' => [
                'ru' => [
                    'NAME' => $name,
                    'SECTION_NAME' => 'Разделы',
                    'ELEMENT_NAME' => 'Элементы',
                ],
                'en' => [
                    'NAME' => $name,
                    'SECTION_NAME' => 'Sections',
                    'ELEMENT_NAME' => 'Elements',
                ],
            ],
        ];

        $obBlockType = new \CIBlockType();
        return (bool)$obBlockType->Add($arFields);
    }

    /**
     * Создаёт инфоблок.
     *
     * @param string $typeId     ID типа инфоблоков.
     * @param string $code       Код инфоблока.
     * @param string $name       Название.
     * @param array  $properties Свойства.
     *
     * @return int ID инфоблока или 0.
     */
    public function createIblock(string $typeId, string $code, string $name, array $properties = []): int
    {
        if (!Loader::includeModule('iblock')) {
            return 0;
        }

        // Проверяем, существует ли инфоблок
        $rsIBlock = \CIBlock::GetList([], ['CODE' => $code, 'TYPE' => $typeId]);
        if ($arIBlock = $rsIBlock->Fetch()) {
            return (int)$arIBlock['ID'];
        }

        $arFields = [
            'ACTIVE' => 'Y',
            'NAME' => $name,
            'CODE' => $code,
            'IBLOCK_TYPE_ID' => $typeId,
            'SITE_ID' => ['s1'],
            'SORT' => 500,
            'VERSION' => 2,
            'GROUP_ID' => ['1' => 'X', '2' => 'R'],
        ];

        $iblock = new \CIBlock();
        $iblockId = $iblock->Add($arFields);

        if (!$iblockId) {
            return 0;
        }

        // Создаём свойства
        foreach ($properties as $propCode => $propData) {
            $this->createProperty($iblockId, $propCode, $propData);
        }

        return $iblockId;
    }

    /**
     * Создаёт свойство инфоблока.
     *
     * @param int    $iblockId ID инфоблока.
     * @param string $code     Код свойства.
     * @param array  $data     Данные свойства.
     *
     * @return int ID свойства или 0.
     */
    public function createProperty(int $iblockId, string $code, array $data): int
    {
        $arProperty = [
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
            'CODE' => $code,
            'NAME' => $data['NAME'],
            'PROPERTY_TYPE' => $data['TYPE'] ?? 'S',
            'MULTIPLE' => $data['MULTIPLE'] ?? 'N',
            'SORT' => $data['SORT'] ?? 500,
        ];

        if (isset($data['USER_TYPE'])) {
            $arProperty['USER_TYPE'] = $data['USER_TYPE'];
        }

        if (isset($data['LINK_IBLOCK_ID'])) {
            $arProperty['LINK_IBLOCK_ID'] = $data['LINK_IBLOCK_ID'];
        }

        if ($data['TYPE'] === 'L' && isset($data['VALUES'])) {
            $arProperty['VALUES'] = $data['VALUES'];
        }

        $ibp = new \CIBlockProperty();
        $propId = $ibp->Add($arProperty);

        return $propId ? (int)$propId : 0;
    }

    /**
     * Создаёт SKU-связь между инфоблоками.
     *
     * @param int $productIblockId ID инфоблока товаров.
     * @param int $offersIblockId  ID инфоблока предложений.
     *
     * @return bool
     */
    public function createSkuRelation(int $productIblockId, int $offersIblockId): bool
    {
        if (!Loader::includeModule('catalog')) {
            return false;
        }

        if ($productIblockId <= 0 || $offersIblockId <= 0) {
            return false;
        }

        // Проверяем существующую связь
        $existingInfo = \CCatalogSKU::GetInfoByProductIBlock($productIblockId);
        if ($existingInfo && (int)$existingInfo['IBLOCK_ID'] === $offersIblockId) {
            return true;
        }

        // Создаём свойство привязки
        $propertyCode = 'CML2_LINK';
        $rsProperty = \CIBlockProperty::GetList(
            [],
            ['IBLOCK_ID' => $offersIblockId, 'CODE' => $propertyCode]
        );

        $propId = 0;

        if ($arProperty = $rsProperty->Fetch()) {
            $propId = (int)$arProperty['ID'];
        } else {
            $arNewProperty = [
                'IBLOCK_ID' => $offersIblockId,
                'ACTIVE' => 'Y',
                'CODE' => $propertyCode,
                'NAME' => 'Элемент каталога',
                'PROPERTY_TYPE' => 'E',
                'MULTIPLE' => 'N',
                'LINK_IBLOCK_ID' => $productIblockId,
                'SORT' => 5,
            ];

            $ibp = new \CIBlockProperty();
            $propId = (int)$ibp->Add($arNewProperty);
        }

        if (!$propId) {
            return false;
        }

        // Регистрируем как каталог
        $catalogExists = \CCatalog::GetByID($offersIblockId);
        if (!$catalogExists) {
            \CCatalog::Add([
                'IBLOCK_ID' => $offersIblockId,
            ]);
        }

        // Регистрируем SKU-связь
        $arCatalog = [
            'IBLOCK_ID' => $offersIblockId,
            'PRODUCT_IBLOCK_ID' => $productIblockId,
            'SKU_PROPERTY_ID' => $propId,
        ];

        return (bool)\CCatalog::Update($offersIblockId, $arCatalog);
    }

    // ============= Создание конкретных инфоблоков =============

    /**
     * Создаёт инфоблок конфигураций калькуляций.
     *
     * @return int
     */
    public function createCalcConfigIblock(): int
    {
        $properties = [
            'PRODUCT_ID' => ['NAME' => 'ID товара', 'TYPE' => 'N'],
            'STATUS' => [
                'NAME' => 'Статус',
                'TYPE' => 'L',
                'VALUES' => [
                    ['VALUE' => 'draft', 'XML_ID' => 'draft'],
                    ['VALUE' => 'active', 'XML_ID' => 'active'],
                    ['VALUE' => 'recalc', 'XML_ID' => 'recalc'],
                ],
            ],
            'LAST_CALC_DATE' => ['NAME' => 'Дата последнего расчёта', 'TYPE' => 'S', 'USER_TYPE' => 'DateTime'],
            'TOTAL_COST' => ['NAME' => 'Итоговая себестоимость', 'TYPE' => 'N'],
            'STRUCTURE' => ['NAME' => 'Структура', 'TYPE' => 'S', 'USER_TYPE' => 'HTML'],
            'USED_MATERIALS' => ['NAME' => 'Использованные материалы', 'TYPE' => 'E', 'MULTIPLE' => 'Y'],
            'USED_WORKS' => ['NAME' => 'Использованные работы', 'TYPE' => 'E', 'MULTIPLE' => 'Y'],
            'USED_EQUIPMENT' => ['NAME' => 'Использованное оборудование', 'TYPE' => 'E', 'MULTIPLE' => 'Y'],
            'USED_DETAILS' => ['NAME' => 'Использованные детали', 'TYPE' => 'E', 'MULTIPLE' => 'Y'],
        ];

        return $this->createIblock('calculator', 'CALC_CONFIG', 'Конфигурации калькуляций', $properties);
    }

    /**
     * Создаёт инфоблок настроек калькуляторов.
     *
     * @return int
     */
    public function createCalcSettingsIblock(): int
    {
        $properties = [
            'CALCULATOR_CODE' => ['NAME' => 'Код калькулятора', 'TYPE' => 'S'],
            'DEFAULT_EQUIPMENT' => ['NAME' => 'Оборудование по умолчанию', 'TYPE' => 'E'],
            'DEFAULT_MATERIAL' => ['NAME' => 'Материал по умолчанию', 'TYPE' => 'E'],
            'DEFAULT_OPTIONS' => ['NAME' => 'Опции по умолчанию', 'TYPE' => 'S', 'USER_TYPE' => 'HTML'],
            'CAN_BE_FIRST' => [
                'NAME' => 'Может быть первым',
                'TYPE' => 'L',
                'VALUES' => [['VALUE' => 'Y'], ['VALUE' => 'N']],
            ],
            'REQUIRES_BEFORE' => ['NAME' => 'Требует перед собой', 'TYPE' => 'S'],
        ];

        return $this->createIblock('calculator', 'CALC_SETTINGS', 'Настройки калькуляторов', $properties);
    }

    /**
     * Создаёт инфоблок материалов.
     *
     * @return int
     */
    public function createMaterialsIblock(): int
    {
        $properties = [
            'PARAMETRS' => ['NAME' => 'Параметры', 'TYPE' => 'S', 'MULTIPLE' => 'Y'],
        ];

        return $this->createIblock('calculator_catalog', 'CALC_MATERIALS', 'Материалы', $properties);
    }

    /**
     * Создаёт инфоблок вариантов материалов.
     *
     * @return int
     */
    public function createMaterialsVariantsIblock(): int
    {
        $properties = [
            'WIDTH' => ['NAME' => 'Ширина, мм', 'TYPE' => 'N'],
            'LENGTH' => ['NAME' => 'Длина, мм', 'TYPE' => 'N'],
            'HEIGHT' => ['NAME' => 'Высота, мм', 'TYPE' => 'N'],
            'DENSITY' => ['NAME' => 'Плотность', 'TYPE' => 'N'],
            'PARAMETRS' => ['NAME' => 'Параметры', 'TYPE' => 'S', 'MULTIPLE' => 'Y'],
        ];

        return $this->createIblock('calculator_catalog', 'CALC_MATERIALS_VARIANTS', 'Варианты материалов', $properties);
    }

    /**
     * Создаёт инфоблок работ.
     *
     * @return int
     */
    public function createWorksIblock(): int
    {
        $properties = [
            'EQUIPMENTS' => ['NAME' => 'Оборудование', 'TYPE' => 'E', 'MULTIPLE' => 'Y'],
            'PARAMETRS' => ['NAME' => 'Параметры', 'TYPE' => 'S', 'MULTIPLE' => 'Y'],
        ];

        return $this->createIblock('calculator_catalog', 'CALC_WORKS', 'Работы', $properties);
    }

    /**
     * Создаёт инфоблок вариантов работ.
     *
     * @return int
     */
    public function createWorksVariantsIblock(): int
    {
        $properties = [
            'WIDTH' => ['NAME' => 'Ширина, мм', 'TYPE' => 'N'],
            'LENGTH' => ['NAME' => 'Длина, мм', 'TYPE' => 'N'],
            'PARAMETRS' => ['NAME' => 'Параметры', 'TYPE' => 'S', 'MULTIPLE' => 'Y'],
        ];

        return $this->createIblock('calculator_catalog', 'CALC_WORKS_VARIANTS', 'Варианты работ', $properties);
    }

    /**
     * Создаёт инфоблок оборудования.
     *
     * @return int
     */
    public function createEquipmentIblock(): int
    {
        $properties = [
            'FIELDS' => ['NAME' => 'Поля печатной машины', 'TYPE' => 'S'],
            'MAX_WIDTH' => ['NAME' => 'Макс. ширина, мм', 'TYPE' => 'N'],
            'MAX_LENGTH' => ['NAME' => 'Макс. длина, мм', 'TYPE' => 'N'],
            'START_COST' => ['NAME' => 'Стоимость приладки', 'TYPE' => 'N'],
            'PARAMETRS' => ['NAME' => 'Параметры', 'TYPE' => 'S', 'MULTIPLE' => 'Y'],
        ];

        return $this->createIblock('calculator_catalog', 'CALC_EQUIPMENT', 'Оборудование', $properties);
    }

    /**
     * Создаёт инфоблок деталей.
     *
     * @return int
     */
    public function createDetailsIblock(): int
    {
        $properties = [
            'PARAMETRS' => ['NAME' => 'Параметры', 'TYPE' => 'S', 'MULTIPLE' => 'Y'],
        ];

        return $this->createIblock('calculator_catalog', 'CALC_DETAILS', 'Детали', $properties);
    }

    /**
     * Создаёт инфоблок вариантов деталей.
     *
     * @return int
     */
    public function createDetailsVariantsIblock(): int
    {
        $properties = [
            'WIDTH' => ['NAME' => 'Ширина, мм', 'TYPE' => 'N'],
            'LENGTH' => ['NAME' => 'Длина, мм', 'TYPE' => 'N'],
            'HEIGHT' => ['NAME' => 'Высота, мм', 'TYPE' => 'N'],
            'PARAMETRS' => ['NAME' => 'Параметры', 'TYPE' => 'S', 'MULTIPLE' => 'Y'],
        ];

        return $this->createIblock('calculator_catalog', 'CALC_DETAILS_VARIANTS', 'Варианты деталей', $properties);
    }
}
