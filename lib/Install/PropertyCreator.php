<?php

namespace Prospektweb\Calc\Install;

use Bitrix\Main\Loader;

/**
 * Класс для создания свойств инфоблоков.
 */
class PropertyCreator
{
    /**
     * Добавляет свойство CALC_CONFIG_ID в инфоблок ТП.
     *
     * @param int $iblockId ID инфоблока.
     *
     * @return int ID свойства или 0.
     */
    public function addCalcConfigProperty(int $iblockId): int
    {
        if (!Loader::includeModule('iblock')) {
            return 0;
        }

        if ($iblockId <= 0) {
            return 0;
        }

        $code = 'CALC_CONFIG_ID';

        // Проверяем, существует ли свойство
        $rsProperty = \CIBlockProperty::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'CODE' => $code]
        );

        if ($arProperty = $rsProperty->Fetch()) {
            return (int)$arProperty['ID'];
        }

        // Создаём свойство
        $arNewProperty = [
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
            'CODE' => $code,
            'NAME' => 'ID конфигурации калькуляции',
            'PROPERTY_TYPE' => 'E', // Привязка к элементу
            'MULTIPLE' => 'N',
            'SORT' => 1000,
        ];

        $ibp = new \CIBlockProperty();
        $propId = $ibp->Add($arNewProperty);

        return $propId ? (int)$propId : 0;
    }

    /**
     * Создаёт свойство.
     *
     * @param int    $iblockId ID инфоблока.
     * @param string $code     Код свойства.
     * @param array  $data     Данные свойства.
     *
     * @return int ID свойства или 0.
     */
    public function createProperty(int $iblockId, string $code, array $data): int
    {
        if (!Loader::includeModule('iblock')) {
            return 0;
        }

        if ($iblockId <= 0) {
            return 0;
        }

        // Проверяем, существует ли свойство
        $rsProperty = \CIBlockProperty::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'CODE' => $code]
        );

        if ($arProperty = $rsProperty->Fetch()) {
            return (int)$arProperty['ID'];
        }

        // Создаём свойство
        $arNewProperty = [
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
            'CODE' => $code,
            'NAME' => $data['NAME'],
            'PROPERTY_TYPE' => $data['TYPE'] ?? 'S',
            'MULTIPLE' => $data['MULTIPLE'] ?? 'N',
            'SORT' => $data['SORT'] ?? 500,
        ];

        if (isset($data['USER_TYPE'])) {
            $arNewProperty['USER_TYPE'] = $data['USER_TYPE'];
        }

        if (isset($data['LINK_IBLOCK_ID'])) {
            $arNewProperty['LINK_IBLOCK_ID'] = $data['LINK_IBLOCK_ID'];
        }

        if ($data['TYPE'] === 'L' && isset($data['VALUES'])) {
            $arNewProperty['VALUES'] = $data['VALUES'];
        }

        $ibp = new \CIBlockProperty();
        $propId = $ibp->Add($arNewProperty);

        return $propId ? (int)$propId : 0;
    }

    /**
     * Удаляет свойство.
     *
     * @param int    $iblockId ID инфоблока.
     * @param string $code     Код свойства.
     *
     * @return bool
     */
    public function deleteProperty(int $iblockId, string $code): bool
    {
        if (!Loader::includeModule('iblock')) {
            return false;
        }

        $rsProperty = \CIBlockProperty::GetList(
            [],
            ['IBLOCK_ID' => $iblockId, 'CODE' => $code]
        );

        if ($arProperty = $rsProperty->Fetch()) {
            return \CIBlockProperty::Delete($arProperty['ID']);
        }

        return true; // Свойство не существует
    }
}
