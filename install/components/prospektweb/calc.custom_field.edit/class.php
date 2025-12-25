<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

/**
 * Компонент для редактирования дополнительных полей калькуляторов
 */
class CalcCustomFieldEditComponent extends CBitrixComponent
{
    protected $iblockId;
    protected $elementId;
    protected $element;
    protected $properties;

    /**
     * Проверка параметров
     */
    public function onPrepareComponentParams($arParams)
    {
        $arParams['IBLOCK_ID'] = (int)($arParams['IBLOCK_ID'] ?? 0);
        $arParams['ELEMENT_ID'] = (int)($arParams['ELEMENT_ID'] ?? 0);

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

        // Загружаем свойства (все, без фильтрации по CODE)
        $rsProps = CIBlockElement::GetProperty(
            $this->iblockId,
            $this->elementId,
            ['sort' => 'asc'],
            []
        );

        $this->properties = [];
        while ($prop = $rsProps->Fetch()) {
            $this->properties[$prop['CODE']] = $prop;
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
            $this->arResult['ERRORS'][] = 'Неверный sessid';
            return false;
        }

        $el = new CIBlockElement();
        
        $arFields = [
            'IBLOCK_ID' => $this->iblockId,
            'NAME' => trim($_POST['NAME'] ?? ''),
            'ACTIVE' => ($_POST['ACTIVE'] ?? 'Y') === 'Y' ? 'Y' : 'N',
        ];

        // Валидация
        if (empty($arFields['NAME'])) {
            $this->arResult['ERRORS'][] = 'Не указано название поля';
            return false;
        }

        $fieldCode = trim($_POST['PROPERTY_VALUES']['FIELD_CODE'] ?? '');
        if (empty($fieldCode)) {
            $this->arResult['ERRORS'][] = 'Не указан символьный код поля';
            return false;
        }

        // Проверка формата кода
        if (!preg_match('/^[A-Z0-9_]+$/', $fieldCode)) {
            $this->arResult['ERRORS'][] = 'Символьный код должен содержать только заглавные латинские буквы, цифры и подчёркивание';
            return false;
        }

        $fieldType = $_POST['PROPERTY_VALUES']['FIELD_TYPE'] ?? '';
        if (empty($fieldType)) {
            $this->arResult['ERRORS'][] = 'Не указан тип поля';
            return false;
        }

        // Сохраняем или обновляем элемент
        if ($this->elementId > 0) {
            $success = $el->Update($this->elementId, $arFields);
        } else {
            $this->elementId = $el->Add($arFields);
            $success = $this->elementId > 0;
        }

        if (!$success) {
            $this->arResult['ERRORS'][] = $el->LAST_ERROR;
            return false;
        }

        // Подготавливаем значения свойств
        $propertyValues = $_POST['PROPERTY_VALUES'] ?? [];
        
        // Обработка OPTIONS - преобразуем в формат VALUE/DESCRIPTION для множественного свойства
        if (isset($_POST['PROPERTY_VALUES']['OPTIONS']) && is_array($_POST['PROPERTY_VALUES']['OPTIONS'])) {
            $options = $_POST['PROPERTY_VALUES']['OPTIONS'];
            $optionValues = [];
            $defaultOptionIndex = (int)($_POST['DEFAULT_OPTION'] ?? -1);
            $newDefaultValue = null;
            
            // Собираем только непустые опции и находим значение по умолчанию
            $currentIndex = 0;
            foreach ($options as $originalIndex => $opt) {
                if (!empty($opt['VALUE']) || !empty($opt['DESCRIPTION'])) {
                    $optionValues[] = [
                        'VALUE' => trim($opt['VALUE'] ?? ''),
                        'DESCRIPTION' => trim($opt['DESCRIPTION'] ?? ''),
                    ];
                    
                    // Если это была выбранная опция по умолчанию, сохраняем её значение
                    // Используем строгое сравнение после приведения типов
                    if ((int)$originalIndex === $defaultOptionIndex) {
                        $newDefaultValue = trim($opt['VALUE'] ?? '');
                    }
                    
                    $currentIndex++;
                }
            }
            
            $propertyValues['OPTIONS'] = $optionValues;
            
            // Устанавливаем значение по умолчанию, если оно было выбрано
            if ($newDefaultValue !== null) {
                $propertyValues['DEFAULT_VALUE'] = $newDefaultValue;
            }
        }
        
        // Сохраняем свойства
        CIBlockElement::SetPropertyValuesEx($this->elementId, $this->iblockId, $propertyValues);

        $this->arResult['SUCCESS'] = true;
        $this->arResult['ELEMENT_ID'] = $this->elementId;

        // Редирект после сохранения
        $listUrl = '/bitrix/admin/iblock_list_admin.php?IBLOCK_ID=' . $this->iblockId . '&type=calculator_catalog&lang=ru';
        LocalRedirect($listUrl);

        return true;
    }

    /**
     * Получение списков для свойств типа "Список"
     */
    protected function getPropertyEnums()
    {
        $enums = [];
        
        $rsProps = CIBlockProperty::GetList(
            [],
            ['IBLOCK_ID' => $this->iblockId, 'PROPERTY_TYPE' => 'L']
        );

        while ($prop = $rsProps->Fetch()) {
            $propId = $prop['ID'];
            $enums[$prop['CODE']] = [];

            $rsEnum = CIBlockPropertyEnum::GetList(
                ['SORT' => 'ASC'],
                ['PROPERTY_ID' => $propId]
            );

            while ($enum = $rsEnum->Fetch()) {
                $enums[$prop['CODE']][] = $enum;
            }
        }

        return $enums;
    }

    /**
     * Выполнение компонента
     */
    public function executeComponent()
    {
        if (!Loader::includeModule('iblock')) {
            ShowError('Модуль Инфоблоков не установлен');
            return;
        }

        $this->iblockId = $this->arParams['IBLOCK_ID'];
        $this->elementId = $this->arParams['ELEMENT_ID'];

        $this->arResult['ERRORS'] = [];
        $this->arResult['SUCCESS'] = false;

        // Обработка сохранения
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
            $this->saveElement();
        }

        // Загрузка данных
        if (!$this->loadElement()) {
            $this->includeComponentTemplate();
            return;
        }

        // Подготовка данных для шаблона
        $this->arResult['IBLOCK_ID'] = $this->iblockId;
        $this->arResult['ELEMENT_ID'] = $this->elementId;
        $this->arResult['ELEMENT'] = $this->element;
        $this->arResult['PROPERTIES'] = $this->properties;
        $this->arResult['PROPERTY_ENUMS'] = $this->getPropertyEnums();

        // URL для возврата к списку
        $this->arResult['LIST_URL'] = '/bitrix/admin/iblock_list_admin.php?IBLOCK_ID=' . $this->iblockId . '&type=calculator_catalog&lang=ru';

        $this->includeComponentTemplate();
    }
}
