<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$forms = [
    '' => 'Не выбрано',
];

if (\Bitrix\Main\Loader::includeModule('iblock')) {
    $iblocks = \CIBlock::GetList(
        [
            'SORT' => 'ASC',
            'NAME' => 'ASC',
        ],
        [
            'TYPE' => 'forms',
            'ACTIVE' => 'Y',
        ]
    );

    while ($iblock = $iblocks->Fetch()) {
        $id = (int)$iblock['ID'];
        $code = trim((string)($iblock['CODE'] ?? ''));
        $name = trim((string)($iblock['NAME'] ?? ''));
        $label = '[' . $id . '] ' . ($name !== '' ? $name : 'Без названия');

        if ($code !== '') {
            $label .= ' (' . $code . ')';
        }

        $forms[$id] = $label;
    }
}

$arComponentParameters = [
    'PARAMETERS' => [
        'IBLOCK_ID' => [
            'PARENT' => 'BASE',
            'NAME' => 'Форма',
            'TYPE' => 'LIST',
            'VALUES' => $forms,
            'DEFAULT' => '',
            'ADDITIONAL_VALUES' => 'N',
        ],
        'IBLOCK_CODE' => [
            'PARENT' => 'BASE',
            'NAME' => 'Символьный код формы, если ID не выбран',
            'TYPE' => 'STRING',
            'DEFAULT' => '',
        ],
        'SUBMIT_TEXT' => [
            'PARENT' => 'BASE',
            'NAME' => 'Текст кнопки отправки',
            'TYPE' => 'STRING',
            'DEFAULT' => 'Отправить',
        ],
        'USE_AJAX' => [
            'PARENT' => 'BASE',
            'NAME' => 'Включить AJAX',
            'TYPE' => 'CHECKBOX',
            'DEFAULT' => 'Y',
        ],
        'CACHE_TYPE' => [
            'PARENT' => 'CACHE_SETTINGS',
            'NAME' => 'Тип кеширования',
            'TYPE' => 'LIST',
            'VALUES' => [
                'A' => 'Авто',
                'Y' => 'Кешировать',
                'N' => 'Не кешировать',
            ],
            'DEFAULT' => 'A',
        ],
        'CACHE_TIME' => [
            'PARENT' => 'CACHE_SETTINGS',
            'NAME' => 'Время кеширования',
            'DEFAULT' => 36000000,
        ],
    ],
];
