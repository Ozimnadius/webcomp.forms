<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

/**
 * Готовит view-model для шаблона формы.
 *
 * Шаблон остается чистым представлением: вся подготовка данных собрана здесь -
 * нормализация значений, генерация HTML-идентификаторов, определение вида
 * контрола, признаки выбранности вариантов и тексты ошибок по полям.
 *
 * Режимы рендера (VIEW.MODE): inline и fragment рисуют блок формы (fragment -
 * без страницы, для ленивой загрузки в попап), popup_shell - только кнопку
 * и пустой dialog, подготовка полей при этом пропускается.
 */

$renderMode = (string)($arResult['RENDER_MODE'] ?? 'inline');

if ($renderMode === 'popup_shell') {
    global $APPLICATION;

    $arResult['VIEW'] = [
        'MODE' => $renderMode,
        'FIELDS' => [],
        'SHOW_SUCCESS' => (bool)($arResult['SHOW_SUCCESS'] ?? false),
        'SHOW_ERROR_ALERT' => false,
        'SYSTEM_ERROR' => '',
        'POPUP' => [
            'MODAL_ID' => 'webcomp-form-dialog-' . randString(8),
            'BUTTON_TEXT' => (string)($arResult['BUTTON_TEXT'] ?? ''),
            'BUTTON_CLASS' => (string)($arResult['BUTTON_CLASS'] ?? ''),
            'FRAGMENT_URL' => $APPLICATION->GetCurPageParam(
                'webcomp_form_render=Y&PARAMS_HASH=' . urlencode((string)($arResult['PARAMS_HASH'] ?? '')),
                ['webcomp_form_render', 'PARAMS_HASH', 'webcomp_form_success']
            ),
        ],
    ];

    return;
}

$formHtmlId = 'webcomp-form-' . (int)($arResult['FORM']['ID'] ?? 0);
$values = is_array($arResult['VALUES'] ?? null) ? $arResult['VALUES'] : [];
$errors = is_array($arResult['ERRORS'] ?? null) ? $arResult['ERRORS'] : [];

$makeHtmlId = static function (string $code, string $suffix = '') use ($formHtmlId): string {
    $htmlId = $formHtmlId . '-' . trim((string)preg_replace('/[^a-zA-Z0-9_-]+/', '-', $code), '-');

    if ($suffix !== '') {
        $htmlId .= '-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $suffix);
    }

    return $htmlId;
};

$toValueList = static function ($value): array {
    $list = is_array($value) ? $value : [$value];

    return array_values(array_filter(array_map(
        static fn($item): string => (string)$item,
        $list
    ), static fn(string $item): bool => $item !== ''));
};

$resolveControl = static function (string $type, array $options): string {
    if (in_array($type, ['textarea', 'select', 'radio'], true)) {
        return $type;
    }

    if ($type === 'checkbox') {
        return $options === [] ? 'checkbox_single' : 'checkbox_list';
    }

    return 'input';
};

$viewFields = [];

foreach ((is_array($arResult['FIELDS'] ?? null) ? $arResult['FIELDS'] : []) as $field) {
    $code = (string)($field['CODE'] ?? '');

    if ($code === '') {
        continue;
    }

    $type = (string)($field['TYPE'] ?? 'text');
    $rawOptions = is_array($field['OPTIONS'] ?? null) ? $field['OPTIONS'] : [];
    $control = $resolveControl($type, $rawOptions);
    $fieldValue = $values[$code] ?? '';
    $valueList = $toValueList($fieldValue);
    $isMultipleSelect = $control === 'select' && (($field['MULTIPLE'] ?? false) === true);

    $options = [];

    foreach ($rawOptions as $index => $option) {
        $optionValue = (string)($option['VALUE'] ?? '');

        if ($optionValue === '') {
            continue;
        }

        $optionText = trim((string)($option['TEXT'] ?? ''));

        $options[] = [
            'VALUE' => $optionValue,
            'TEXT' => $optionText !== '' ? $optionText : $optionValue,
            'SELECTED' => in_array($optionValue, $valueList, true),
            'HTML_ID' => $makeHtmlId($code, (string)$index),
        ];
    }

    $viewFields[] = [
        'CONTROL' => $control,
        'INPUT_NAME' => ($control === 'checkbox_list' || $isMultipleSelect) ? $code . '[]' : $code,
        'INPUT_TYPE' => in_array($type, ['text', 'email', 'tel', 'url', 'number', 'date'], true) ? $type : 'text',
        'HTML_ID' => $makeHtmlId($code),
        'LABEL' => (string)($field['LABEL'] ?? ($field['NAME'] ?? $code)),
        'PLACEHOLDER' => (string)($field['PLACEHOLDER'] ?? ''),
        'REQUIRED' => (bool)($field['REQUIRED'] ?? false),
        'ERROR' => (string)($errors[$code] ?? ''),
        'VALUE' => is_array($fieldValue) ? '' : (string)$fieldValue,
        'IS_MULTIPLE_SELECT' => $isMultipleSelect,
        'IS_CHECKED' => !is_array($fieldValue) && (string)$fieldValue === 'Y',
        'OPTIONS' => $options,
    ];
}

$arResult['VIEW'] = [
    'MODE' => $renderMode,
    'FORM_HTML_ID' => $formHtmlId,
    'FORM_ACTION' => (string)($arResult['FORM_ACTION'] ?? ''),
    'FIELDS' => $viewFields,
    'SHOW_SUCCESS' => (bool)($arResult['SHOW_SUCCESS'] ?? false),
    'SHOW_ERROR_ALERT' => (bool)($arResult['IS_SUBMITTED'] ?? false) && $errors !== [],
    'SYSTEM_ERROR' => (string)($errors['SYSTEM'] ?? ''),
];
