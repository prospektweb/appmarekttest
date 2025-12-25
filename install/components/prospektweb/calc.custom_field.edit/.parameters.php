<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$arComponentParameters = [
    'PARAMETERS' => [
        'IBLOCK_ID' => [
            'NAME' => 'ID инфоблока',
            'TYPE' => 'STRING',
            'DEFAULT' => '',
        ],
        'ELEMENT_ID' => [
            'NAME' => 'ID элемента',
            'TYPE' => 'STRING',
            'DEFAULT' => '0',
        ],
    ],
];
