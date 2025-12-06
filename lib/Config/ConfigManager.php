<?php

namespace Prospektweb\Calc\Config;

use Bitrix\Main\Config\Option;

/**
 * Менеджер конфигурации модуля.
 */
class ConfigManager
{
    /** @var string ID модуля */
    protected const MODULE_ID = 'prospektweb.calc';

    /** @var array Кеш ID инфоблоков */
    protected static array $iblockCache = [];

    /**
     * Получает ID инфоблока по коду.
     *
     * @param string $code Код инфоблока.
     *
     * @return int ID инфоблока.
     */
    public function getIblockId(string $code): int
    {
        if (isset(self::$iblockCache[$code])) {
            return self::$iblockCache[$code];
        }

        $id = (int)Option::get(self::MODULE_ID, 'IBLOCK_' . $code, 0);
        self::$iblockCache[$code] = $id;

        return $id;
    }

    /**
     * Устанавливает ID инфоблока.
     *
     * @param string $code Код инфоблока.
     * @param int    $id   ID инфоблока.
     */
    public function setIblockId(string $code, int $id): void
    {
        Option::set(self::MODULE_ID, 'IBLOCK_' . $code, $id);
        self::$iblockCache[$code] = $id;
    }

    /**
     * Получает ID инфоблока товаров.
     *
     * @return int
     */
    public function getProductIblockId(): int
    {
        return (int)Option::get(self::MODULE_ID, 'PRODUCT_IBLOCK_ID', 0);
    }

    /**
     * Получает ID инфоблока торговых предложений.
     *
     * @return int
     */
    public function getSkuIblockId(): int
    {
        return (int)Option::get(self::MODULE_ID, 'SKU_IBLOCK_ID', 0);
    }

    /**
     * Получает настройку модуля.
     *
     * @param string $name    Имя настройки.
     * @param mixed  $default Значение по умолчанию.
     *
     * @return mixed
     */
    public function getOption(string $name, $default = null)
    {
        return Option::get(self::MODULE_ID, $name, $default);
    }

    /**
     * Устанавливает настройку модуля.
     *
     * @param string $name  Имя настройки.
     * @param mixed  $value Значение.
     */
    public function setOption(string $name, $value): void
    {
        Option::set(self::MODULE_ID, $name, $value);
    }

    /**
     * Получает все ID инфоблоков модуля.
     *
     * @return array Массив [код => id].
     */
    public function getAllIblockIds(): array
    {
        $codes = [
            'CALC_CONFIG',
            'CALC_SETTINGS',
            'CALC_MATERIALS',
            'CALC_MATERIALS_VARIANTS',
            'CALC_WORKS',
            'CALC_WORKS_VARIANTS',
            'CALC_EQUIPMENT',
            'CALC_DETAILS',
            'CALC_DETAILS_VARIANTS',
        ];

        $result = [];
        foreach ($codes as $code) {
            $result[$code] = $this->getIblockId($code);
        }

        return $result;
    }

    /**
     * Очищает кеш ID инфоблоков.
     */
    public function clearCache(): void
    {
        self::$iblockCache = [];
    }
}
