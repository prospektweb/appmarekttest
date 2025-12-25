<?php

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

if (! defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

Loc::loadMessages(__FILE__);

class CalcCustomFieldEditComponent extends CBitrixComponent
{
    protected $iblockId;
    protected $elementId;
    protected $element;
    protected $properties;

    /**
     * Подготовка параметров компонента
     */
    public function onPrepareComponentParams($arParams)
    {
        $arParams['IBLOCK_ID'] = (int)($arParams['IBLOCK_ID'] ?? 0);
        $arParams['ELEMENT_ID'] = (int)($arParams['ELEMENT_ID'] ?? 0);
        $arParams['BACK_URL'] = $arParams['BACK_URL'] ?? '';

        return $arParams;
    }

    /**
     * Загрузка данных элемента
     */
    protected function loadElement()
    {
        if ($this->elementId <= 0) {
            return true;
        }

        // Загружаем основные поля элемента
        $rsElement = CIBlockElement::GetByID($this->elementId);
        if (!$rsElement) {
            $this->arResult['ERRORS'][] = 'Ошибка загрузки элемента';
            return false;
        }

        $this->element = $rsElement->Fetch();

        if (!$this->element) {
            $this->arResult['ERRORS'][] = 'Элемент не найден';
            return false;
        }

        // Загружаем свойства через GetProperty
        $rsProps = CIBlockElement::GetProperty(
            $this->iblockId,
            $this->elementId,
            ['SORT' => 'ASC'],
            []
        );

        $this->properties = [];
        while ($prop = $rsProps->Fetch()) {
            $code = $prop['CODE'];

            // Для множественных свойств (например OPTIONS) собираем в массив
            if ($prop['MULTIPLE'] === 'Y') {
                if (! isset($this->properties[$code])) {
                    $this->properties[$code] = [
                        'CODE' => $code,
                        'PROPERTY_TYPE' => $prop['PROPERTY_TYPE'],
                        'MULTIPLE' => 'Y',
                        'VALUES' => [],
                    ];
                }
                // Добавляем значение в массив (только если есть данные)
                if (!empty($prop['VALUE']) || !empty($prop['DESCRIPTION'])) {
                    $this->properties[$code]['VALUES'][] = [
                        'VALUE' => $prop['VALUE'],
                        'DESCRIPTION' => $prop['DESCRIPTION'],
                    ];
                }
            } else {
                // Для одиночных свойств
                $this->properties[$code] = [
                    'CODE' => $code,
                    'PROPERTY_TYPE' => $prop['PROPERTY_TYPE'],
                    'MULTIPLE' => 'N',
                    'VALUE' => $prop['VALUE'],
                    'VALUE_XML_ID' => $prop['VALUE_XML_ID'] ?? null,
                    'VALUE_ENUM_ID' => $prop['VALUE_ENUM_ID'] ?? null,
                    'DESCRIPTION' => $prop['DESCRIPTION'] ?? null,
                ];
            }
        }

        return true;
    }

    /**
     * Сохранение элемента
     */
    protected function saveElement()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return false;
        }

        if (!check_bitrix_sessid()) {
            $this->arResult['ERRORS'][] = 'Ошибка безопасности.  Обновите страницу и попробуйте снова.';
            return false;
        }

        // Проверяем, нажата ли кнопка сохранения
        if (empty($_POST['save']) && empty($_POST['apply'])) {
            return false;
        }

        $el = new CIBlockElement();

        // Основные поля
        $arFields = [
            'IBLOCK_ID' => $this->iblockId,
            'NAME' => trim($_POST['NAME'] ?? ''),
            'ACTIVE' => ! empty($_POST['ACTIVE']) ? 'Y' : 'N',
        ];

        // Валидация названия
        if (empty($arFields['NAME'])) {
            $this->arResult['ERRORS'][] = 'Не указано название поля';
            return false;
        }

        // Получаем значения свойств
        $propValues = $_POST['PROPERTY_VALUES'] ?? [];

        // Валидация символьного кода
        $fieldCode = trim($propValues['FIELD_CODE'] ?? '');
        if (empty($fieldCode)) {
            $this->arResult['ERRORS'][] = 'Не указан символьный код поля';
            return false;
        }
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $fieldCode)) {
            $this->arResult['ERRORS'][] = 'Символьный код должен начинаться с буквы и содержать только заглавные латинские буквы, цифры и подчёркивание';
            return false;
        }

        // Валидация типа поля
        $fieldType = $propValues['FIELD_TYPE'] ?? '';
        $allowedTypes = ['number', 'text', 'checkbox', 'select'];
        if (empty($fieldType) || !in_array($fieldType, $allowedTypes)) {
            $this->arResult['ERRORS'][] = 'Не выбран тип поля';
            return false;
        }

        // Обработка значения по умолчанию
        $defaultValue = '';
        if ($fieldType === 'checkbox') {
            $defaultValue = ! empty($propValues['DEFAULT_VALUE']) ? 'Y' : 'N';
        } elseif ($fieldType === 'select') {
            // Для select берём из DEFAULT_OPTION (radio)
            $defaultValue = $_POST['DEFAULT_OPTION'] ?? '';
        } else {
            $defaultValue = trim($propValues['DEFAULT_VALUE'] ?? '');
        }

        // Обработка OPTIONS для select
        $optionsValues = [];
        if ($fieldType === 'select' && !empty($propValues['OPTIONS'])) {
            foreach ($propValues['OPTIONS'] as $opt) {
                $optValue = trim($opt['VALUE'] ?? '');
                $optDesc = trim($opt['DESCRIPTION'] ?? '');
                if (!empty($optValue) || !empty($optDesc)) {
                    $optionsValues[] = [
                        'VALUE' => $optValue,
                        'DESCRIPTION' => $optDesc,
                    ];
                }
            }
        }

        // Формируем массив свойств
        $arFields['PROPERTY_VALUES'] = [
            'FIELD_CODE' => $fieldCode,
            'FIELD_TYPE' => $fieldType,
            'DEFAULT_VALUE' => $defaultValue,
            'IS_REQUIRED' => ! empty($propValues['IS_REQUIRED']) ? 'Y' : 'N',
            'UNIT' => $fieldType === 'number' ? trim($propValues['UNIT'] ?? '') : '',
            'MIN_VALUE' => $fieldType === 'number' ? $propValues['MIN_VALUE'] :  '',
            'MAX_VALUE' => $fieldType === 'number' ? $propValues['MAX_VALUE'] : '',
            'STEP_VALUE' => $fieldType === 'number' ? $propValues['STEP_VALUE'] : '',
            'MAX_LENGTH' => $fieldType === 'text' ? $propValues['MAX_LENGTH'] : '',
            'OPTIONS' => $optionsValues,
            'SORT_ORDER' => (int)($propValues['SORT_ORDER'] ?? 500),
        ];

        // Создание или обновление
        if ($this->elementId > 0) {
            $success = $el->Update($this->elementId, $arFields);
        } else {
            $this->elementId = $el->Add($arFields);
            $success = $this->elementId > 0;
        }

        if (! $success) {
            $this->arResult['ERRORS'][] = 'Ошибка сохранения:  ' . $el->LAST_ERROR;
            return false;
        }

        // Редирект после сохранения
        if (! empty($_POST['save'])) {
            // Кнопка "Сохранить" — возврат к списку
            $backUrl = $this->arParams['BACK_URL'] ?: '/bitrix/admin/iblock_list_admin.php? IBLOCK_ID=' . $this->iblockId .  '&type=calculator&lang=' .  LANGUAGE_ID;
            LocalRedirect($backUrl);
        } else {
            // Кнопка "Применить" — остаёмся на странице
            $this->arResult['SUCCESS_MESSAGE'] = 'Изменения сохранены';
            // Перезагружаем элемент
            $this->loadElement();
        }

        return true;
    }

    /**
     * Выполнение компонента
     */
    public function executeComponent()
    {
        if (! Loader::includeModule('iblock')) {
            $this->arResult['ERRORS'][] = 'Модуль iblock не установлен';
            $this->includeComponentTemplate();
            return;
        }

        $this->iblockId = $this->arParams['IBLOCK_ID'];
        $this->elementId = $this->arParams['ELEMENT_ID'];

        if ($this->iblockId <= 0) {
            $this->arResult['ERRORS'][] = 'Не указан ID инфоблока';
            $this->includeComponentTemplate();
            return;
        }

        $this->arResult['ERRORS'] = [];
        $this->arResult['IS_NEW'] = ($this->elementId <= 0);

        // Сначала пытаемся сохранить
        $this->saveElement();

        // Затем загружаем данные (или загружаем заново после применения)
        if (empty($this->arResult['SUCCESS_MESSAGE'])) {
            $this->loadElement();
        }

        // Передаём данные в шаблон
        $this->arResult['ELEMENT'] = $this->element ??  [];
        $this->arResult['PROPERTIES'] = $this->properties ?? [];

        $this->includeComponentTemplate();
    }
}
