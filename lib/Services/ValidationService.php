<?php

namespace Prospektweb\Calc\Services;

/**
 * Сервис для валидации данных калькуляторов.
 */
class ValidationService
{
    /**
     * Проверяет, что все обязательные числовые поля заполнены.
     *
     * @param array    $data           Массив данных для проверки.
     * @param string[] $requiredFields Список обязательных полей.
     *
     * @return array Массив ошибок.
     */
    public function validateRequiredNumbers(array $data, array $requiredFields): array
    {
        $errors = [];

        foreach ($requiredFields as $field) {
            $value = $this->getNestedValue($data, $field);

            if ($value === null || $value === '') {
                $errors[] = [
                    'field' => $field,
                    'message' => "Поле {$field} не заполнено",
                ];
                continue;
            }

            if (!is_numeric($value)) {
                $errors[] = [
                    'field' => $field,
                    'message' => "Поле {$field} должно быть числом",
                ];
            }
        }

        return $errors;
    }

    /**
     * Проверяет, что значение является положительным числом.
     *
     * @param mixed  $value     Значение для проверки.
     * @param string $fieldName Название поля.
     *
     * @return string|null Сообщение об ошибке или null.
     */
    public function validatePositiveNumber($value, string $fieldName): ?string
    {
        if ($value === null || $value === '') {
            return "Поле {$fieldName} не заполнено";
        }

        if (!is_numeric($value)) {
            return "Поле {$fieldName} должно быть числом";
        }

        if ((float)$value <= 0) {
            return "Поле {$fieldName} должно быть положительным числом";
        }

        return null;
    }

    /**
     * Проверяет, что ID элемента валиден.
     *
     * @param mixed  $id        ID для проверки.
     * @param string $fieldName Название поля.
     *
     * @return string|null Сообщение об ошибке или null.
     */
    public function validateId($id, string $fieldName): ?string
    {
        if ($id === null || $id === '') {
            return "Не указан {$fieldName}";
        }

        $intId = (int)$id;
        if ($intId <= 0) {
            return "Некорректный {$fieldName}: должен быть положительным целым числом";
        }

        return null;
    }

    /**
     * Формирует HTML-ссылку на элемент инфоблока в админке.
     *
     * @param array $fields Массив полей элемента.
     *
     * @return string HTML-ссылка или текстовое представление.
     */
    public function buildElementLink(array $fields): string
    {
        $id = isset($fields['ID']) ? (int)$fields['ID'] : 0;
        $iblockId = isset($fields['IBLOCK_ID']) ? (int)$fields['IBLOCK_ID'] : 0;
        $name = $fields['NAME'] ?? '';

        if ($id <= 0 || $iblockId <= 0) {
            return $name !== '' ? $name : 'ID ' . $id;
        }

        // Use LANGUAGE_ID if defined, otherwise try to get from site settings
        $lang = 'ru'; // Default fallback
        if (defined('LANGUAGE_ID')) {
            $lang = LANGUAGE_ID;
        } elseif (defined('SITE_ID')) {
            // Try to get site language
            $siteId = SITE_ID;
            if (function_exists('CSite') && class_exists('CSite')) {
                $rsSite = \CSite::GetByID($siteId);
                if ($arSite = $rsSite->Fetch()) {
                    $lang = $arSite['LANGUAGE_ID'] ?? 'ru';
                }
            }
        }

        // Determine iblock type from the iblock itself
        $iblockType = 'catalog'; // Default
        if ($iblockId > 0 && \Bitrix\Main\Loader::includeModule('iblock')) {
            $rsIBlock = \CIBlock::GetByID($iblockId);
            if ($arIBlock = $rsIBlock->Fetch()) {
                $iblockType = $arIBlock['IBLOCK_TYPE_ID'] ?? 'catalog';
            }
        }

        $url = '/bitrix/admin/iblock_element_edit.php?IBLOCK_ID=' . $iblockId
            . '&type=' . urlencode($iblockType) . '&ID=' . $id . '&lang=' . urlencode($lang) . '&find_section_section=0';

        $safeName = $name !== '' ? $name : ('ID ' . $id);

        if (function_exists('htmlspecialcharsbx')) {
            $url = htmlspecialcharsbx($url);
            $safeName = htmlspecialcharsbx($safeName);
        } else {
            $url = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $safeName = htmlspecialchars($safeName, ENT_QUOTES, 'UTF-8');
        }

        return '<a href="' . $url . '" target="_blank">' . $safeName . '</a>';
    }

    /**
     * Формирует сообщение об ошибке с ссылкой на элемент.
     *
     * @param string $message Текст сообщения.
     * @param array  $fields  Массив полей элемента.
     *
     * @return string Сообщение с HTML-ссылкой.
     */
    public function buildErrorWithLink(string $message, array $fields): string
    {
        $link = $this->buildElementLink($fields);
        return $message . ' ' . $link;
    }

    /**
     * Проверяет размеры материала.
     *
     * @param array $materialData Данные материала.
     *
     * @return array|null Массив с ошибкой или null.
     */
    public function validateMaterialDimensions(array $materialData): ?array
    {
        $width = $this->extractNumberField($materialData, 'WIDTH');
        $length = $this->extractNumberField($materialData, 'LENGTH');

        if ($width === null || $length === null) {
            return [
                'field' => 'WIDTH/LENGTH',
                'message' => 'Не заполнены ширина или длина материала',
                'fields' => $materialData['FIELDS'] ?? [],
            ];
        }

        if ($width <= 0 || $length <= 0) {
            return [
                'field' => 'WIDTH/LENGTH',
                'message' => 'Ширина и длина материала должны быть положительными',
                'fields' => $materialData['FIELDS'] ?? [],
            ];
        }

        return null;
    }

    /**
     * Извлекает числовое значение из данных.
     *
     * @param array  $data Массив данных.
     * @param string $key  Ключ поля.
     *
     * @return float|null
     */
    protected function extractNumberField(array $data, string $key): ?float
    {
        // Проверяем в свойствах
        if (isset($data['PROPERTIES'][$key]['VALUE'])) {
            $value = $data['PROPERTIES'][$key]['VALUE'];
            if (is_numeric($value)) {
                return (float)$value;
            }
        }

        // Проверяем в CATALOG.PRODUCT
        if (isset($data['CATALOG']['PRODUCT'][$key])) {
            $value = $data['CATALOG']['PRODUCT'][$key];
            if (is_numeric($value)) {
                return (float)$value;
            }
        }

        return null;
    }

    /**
     * Получает вложенное значение из массива по пути.
     *
     * @param array  $data Массив данных.
     * @param string $path Путь к значению.
     *
     * @return mixed|null
     */
    protected function getNestedValue(array $data, string $path)
    {
        $keys = explode('.', $path);
        $current = $data;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        return $current;
    }
}
