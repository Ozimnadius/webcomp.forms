<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$forms = [
    '' => 'Не выбрано',
];

if (\Bitrix\Main\Loader::includeModule('iblock') && \Bitrix\Main\Loader::includeModule('webcomp.forms')) {
    $iblocks = \CIBlock::GetList(
        [
            'SORT' => 'ASC',
            'NAME' => 'ASC',
        ],
        [
            'TYPE' => \Webcomp\Forms\Form\IblockTypeInstaller::TYPE_ID,
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
        'DISPLAY_MODE' => [
            'PARENT' => 'BASE',
            'NAME' => 'Режим отображения',
            'TYPE' => 'LIST',
            'VALUES' => [
                'inline' => 'Инлайн-форма',
                'popup' => 'Кнопка с всплывающей формой',
            ],
            'DEFAULT' => 'inline',
        ],
        'BUTTON_TEXT' => [
            'PARENT' => 'BASE',
            'NAME' => 'Текст кнопки попапа',
            'TYPE' => 'STRING',
            'DEFAULT' => 'Открыть форму',
        ],
        'BUTTON_CLASS' => [
            'PARENT' => 'BASE',
            'NAME' => 'CSS-классы кнопки попапа',
            'TYPE' => 'STRING',
            'DEFAULT' => 'btn btn-primary',
        ],
    ],
];
