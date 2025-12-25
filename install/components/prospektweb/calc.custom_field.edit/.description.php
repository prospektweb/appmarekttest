<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$arComponentDescription = [
    'NAME' => 'Редактирование дополнительного поля',
    'DESCRIPTION' => 'Кастомная форма для редактирования элементов инфоблока CALC_CUSTOM_FIELDS',
    'ICON' => '/images/icon.gif',
    'PATH' => [
        'ID' => 'prospektweb',
        'NAME' => 'PROSPEKT-WEB',
        'CHILD' => [
            'ID' => 'calc',
            'NAME' => 'Калькулятор',
        ],
    ],
];
