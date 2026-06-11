<?php

namespace Webcomp\Forms\PropertyTypes;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Page\Asset;

Loc::loadMessages(__FILE__);

/**
 * Пользовательский тип свойства инфоблока "Поле формы".
 *
 * Свойство хранится как строковое свойство Bitrix, но через настройки
 * USER_TYPE_SETTINGS описывает будущее поле публичной формы: HTML-тип,
 * подпись, placeholder, обязательность и варианты значений для списочных
 * контролов.
 */
class FormBuilder
{
    /**
     * Базовый тип свойства Bitrix, на котором построен пользовательский тип.
     */
    public const PROPERTY_TYPE = 'S';

    /**
     * Код пользовательского типа свойства.
     */
    public const USER_TYPE = 'formbuilder';

    /**
     * Значение option в select выбора типа свойства в админке Bitrix.
     */
    public const USER_TYPE_SELECT_VALUE = self::PROPERTY_TYPE . ':' . self::USER_TYPE;

    /**
     * Путь к установленному CSS админского поля.
     */
    private const ASSET_CSS = '/bitrix/css/webcomp.forms/formbuilder.css';

    /**
     * Типы полей, для которых используются варианты значений.
     */
    private const FIELD_TYPES_WITH_OPTIONS = [
        'select' => true,
        'checkbox' => true,
        'radio' => true,
    ];

    /**
     * Флаг, предотвращающий повторное подключение CSS в рамках одного запроса.
     */
    private static bool $assetsIncluded = false;

    /**
     * Возвращает описание пользовательского типа свойства для Bitrix.
     *
     * Метод регистрируется на событии OnIBlockPropertyBuildList и сообщает
     * ядру Bitrix базовый тип свойства, код пользовательского типа и callbacks.
     *
     * @return array<string, mixed>
     */
    public static function GetUserTypeDescription(): array
    {
        return [
            'PROPERTY_TYPE' => self::PROPERTY_TYPE,
            'USER_TYPE' => self::USER_TYPE,
            'DESCRIPTION' => Loc::getMessage('WEBCOMP_FORMS_FORMBUILDER_NAME') ?: 'Поле формы',
            'GetPropertyFieldHtml' => [__CLASS__, 'GetPropertyFieldHtml'],
            'GetAdminListViewHTML' => [__CLASS__, 'GetAdminListViewHTML'],
            'PrepareSettings' => [__CLASS__, 'PrepareSettings'],
            'GetSettingsHTML' => [__CLASS__, 'GetSettingsHTML'],
            'ConvertToDB' => [__CLASS__, 'ConvertToDB'],
        ];
    }

    /**
     * Рендерит поле ввода значения свойства в административной форме элемента.
     *
     * Тип HTML-контрола определяется настройкой FIELD_TYPE: input, textarea,
     * select, checkbox или radio.
     *
     * @param array $arProperty Описание свойства инфоблока.
     * @param array $value Текущее значение свойства.
     * @param array $strHTMLControlName Имена HTML-контролов, переданные Bitrix.
     *
     * @return string HTML поля значения.
     */
    public static function GetPropertyFieldHtml($arProperty, $value, $strHTMLControlName): string
    {
        self::includeAssets();

        $settings = self::PrepareSettings($arProperty);
        $inputName = htmlspecialcharsbx($strHTMLControlName['VALUE']);
        $rawValue = $value['VALUE'] ?? '';
        $fieldType = $settings['FIELD_TYPE'];

        if ($fieldType === 'textarea') {
            return '<textarea name="' . $inputName . '" rows="5" cols="50" placeholder="' . htmlspecialcharsbx($settings['PLACEHOLDER']) . '">'
                . htmlspecialcharsbx((string)$rawValue)
                . '</textarea>';
        }

        if ($fieldType === 'select') {
            return self::renderSelect($inputName, (string)$rawValue, $settings['OPTIONS'], $settings['PLACEHOLDER']);
        }

        if ($fieldType === 'checkbox') {
            return self::renderCheckboxes($inputName, $rawValue, $settings['OPTIONS']);
        }

        if ($fieldType === 'radio') {
            return self::renderRadios($inputName, (string)$rawValue, $settings['OPTIONS']);
        }

        $htmlType = self::getInputHtmlType($fieldType);

        return '<input type="' . $htmlType . '" name="' . $inputName . '" value="'
            . htmlspecialcharsbx((string)$rawValue)
            . '" placeholder="' . htmlspecialcharsbx($settings['PLACEHOLDER'])
            . '" class="adm-input">';
    }

    /**
     * Рендерит значение свойства в административном списке элементов.
     *
     * Для списочных контролов технические значения заменяются на человекочитаемый
     * текст из настроек OPTIONS.
     *
     * @param array $arProperty Описание свойства инфоблока.
     * @param array $value Значение свойства.
     * @param array $strHTMLControlName Имена HTML-контролов, переданные Bitrix.
     *
     * @return string Экранированное значение для списка.
     */
    public static function GetAdminListViewHTML($arProperty, $value, $strHTMLControlName): string
    {
        $settings = self::PrepareSettings($arProperty);
        $values = self::normalizeValueList($value['VALUE'] ?? '');
        $values = self::mapValuesToOptionTexts($values, $settings['OPTIONS']);

        return htmlspecialcharsbx(implode(', ', $values));
    }

    /**
     * Нормализует настройки пользовательского свойства.
     *
     * Метод используется и в админском рендере, и в FormRepository, чтобы модуль
     * одинаково трактовал тип поля, подпись, placeholder, обязательность и
     * варианты значений.
     *
     * @param array $arProperty Описание свойства с USER_TYPE_SETTINGS.
     *
     * @return array{FIELD_TYPE: string, LABEL: string, PLACEHOLDER: string, REQUIRED: string, OPTIONS: array<int, array{VALUE: string, TEXT: string}>}
     */
    public static function PrepareSettings($arProperty): array
    {
        $settings = self::normalizeSettings($arProperty['USER_TYPE_SETTINGS'] ?? []);

        $fieldTypes = self::getFieldTypes();
        $fieldType = (string)($settings['FIELD_TYPE'] ?? 'text');

        if (!isset($fieldTypes[$fieldType])) {
            $fieldType = 'text';
        }

        return [
            'FIELD_TYPE' => $fieldType,
            'LABEL' => trim((string)($settings['LABEL'] ?? '')),
            'PLACEHOLDER' => trim((string)($settings['PLACEHOLDER'] ?? '')),
            'REQUIRED' => (($settings['REQUIRED'] ?? 'N') === 'Y') ? 'Y' : 'N',
            'OPTIONS' => self::prepareOptions($settings['OPTIONS'] ?? []),
        ];
    }

    /**
     * Приводит USER_TYPE_SETTINGS к массиву независимо от способа чтения свойства.
     *
     * В админских callback-методах Bitrix обычно передает настройки уже массивом,
     * а D7 PropertyTable может вернуть сериализованную строку из базы.
     *
     * @param mixed $settings Настройки пользовательского свойства.
     *
     * @return array
     */
    private static function normalizeSettings($settings): array
    {
        if (is_array($settings)) {
            return $settings;
        }

        if (!is_string($settings) || trim($settings) === '') {
            return [];
        }

        $unserialized = @unserialize($settings, ['allowed_classes' => false]);

        return is_array($unserialized) ? $unserialized : [];
    }

    /**
     * Рендерит дополнительные настройки свойства в модальном окне Bitrix.
     *
     * Настройки описывают будущее поле публичной формы: тип HTML-контрола,
     * подпись, placeholder, обязательность и варианты значений.
     *
     * @param array $arProperty Описание свойства инфоблока.
     * @param array $strHTMLControlName Имена HTML-контролов настроек.
     * @param array $arPropertyFields Описание стандартных полей настройки свойства.
     *
     * @return string HTML строк настроек свойства.
     */
    public static function GetSettingsHTML($arProperty, $strHTMLControlName, &$arPropertyFields): string
    {
        self::includeAssets();

        $settings = self::PrepareSettings($arProperty);
        $settingsName = htmlspecialcharsbx($strHTMLControlName['NAME']);
        $fieldTypeOptions = '';
        $optionsRows = self::renderOptionSettingsRows($settingsName, $settings['OPTIONS']);
        $optionsNextIndex = max(1, count($settings['OPTIONS']));
        $optionsRowStyle = isset(self::FIELD_TYPES_WITH_OPTIONS[$settings['FIELD_TYPE']]) ? '' : ' style="display:none"';
        $fieldTypeChangeHandler = htmlspecialcharsbx(self::getFieldTypeChangeHandler());
        $addOptionHandler = htmlspecialcharsbx(self::getAddOptionHandler());

        foreach (self::getFieldTypes() as $value => $label) {
            $fieldTypeOptions .= '<option value="' . htmlspecialcharsbx($value) . '"'
                . ($settings['FIELD_TYPE'] === $value ? ' selected' : '')
                . '>' . htmlspecialcharsbx($label) . '</option>';
        }

        return '
            <tr valign="top">
                <td>Тип поля формы:</td>
                <td><select name="' . $settingsName . '[FIELD_TYPE]" data-webcomp-forms-field-type onchange="' . $fieldTypeChangeHandler . '">' . $fieldTypeOptions . '</select></td>
            </tr>
            <tr valign="top">
                <td>Подпись поля:</td>
                <td><input type="text" name="' . $settingsName . '[LABEL]" value="' . htmlspecialcharsbx($settings['LABEL']) . '" size="50"></td>
            </tr>
            <tr valign="top">
                <td>Placeholder:</td>
                <td><input type="text" name="' . $settingsName . '[PLACEHOLDER]" value="' . htmlspecialcharsbx($settings['PLACEHOLDER']) . '" size="50"></td>
            </tr>
            <tr valign="top">
                <td>Обязательное:</td>
                <td>
                    <input type="hidden" name="' . $settingsName . '[REQUIRED]" value="N">
                    <input type="checkbox" name="' . $settingsName . '[REQUIRED]" value="Y"' . ($settings['REQUIRED'] === 'Y' ? ' checked' : '') . '>
                </td>
            </tr>
            <tr valign="top" data-webcomp-forms-options-row' . $optionsRowStyle . '>
                <td>Варианты значений:</td>
                <td>
                    <div class="webcomp-forms-property-settings__options" data-webcomp-forms-options data-name="' . $settingsName . '[OPTIONS]" data-next-index="' . $optionsNextIndex . '">' . $optionsRows . '</div>
                    <button type="button" class="adm-btn" style="margin-top:8px;" data-webcomp-forms-add-option onclick="' . $addOptionHandler . '">Добавить</button>
                </td>
            </tr>
        ';
    }

    /**
     * Подготавливает значение свойства к сохранению в базе.
     *
     * Множественные значения checkbox сохраняются JSON-строкой, одиночные
     * значения сохраняются как строка.
     *
     * @param array $arProperty Описание свойства инфоблока.
     * @param array $value Значение, переданное Bitrix.
     *
     * @return array{VALUE: string}
     */
    public static function ConvertToDB($arProperty, $value): array
    {
        $rawValue = $value['VALUE'] ?? '';

        if (is_array($rawValue)) {
            $rawValue = array_values(array_filter(array_map(
                static fn($item): string => trim((string)$item),
                $rawValue
            ), static fn($item): bool => $item !== ''));

            $rawValue = $rawValue === []
                ? ''
                : json_encode($rawValue, JSON_UNESCAPED_UNICODE);
        }

        return [
            'VALUE' => (string)$rawValue,
        ];
    }

    /**
     * Рендерит select для значения свойства.
     *
     * @param string $inputName Имя HTML-поля.
     * @param string $selectedValue Текущее выбранное значение.
     * @param array<int, array{VALUE: string, TEXT: string}> $options Варианты значений.
     * @param string $placeholder Placeholder для пустого варианта.
     *
     * @return string HTML select.
     */
    private static function renderSelect(string $inputName, string $selectedValue, array $options, string $placeholder): string
    {
        $html = '<select name="' . $inputName . '">';
        $html .= '<option value="">' . htmlspecialcharsbx($placeholder) . '</option>';

        foreach ($options as $option) {
            $optionValue = self::getOptionValue($option);
            $optionText = self::getOptionText($option);

            $html .= '<option value="' . htmlspecialcharsbx($optionValue) . '"' . ($selectedValue === $optionValue ? ' selected' : '') . '>'
                . htmlspecialcharsbx($optionText)
                . '</option>';
        }

        return $html . '</select>';
    }

    /**
     * Рендерит checkbox или группу checkbox для значения свойства.
     *
     * Если варианты не заданы, используется одиночный checkbox со значениями Y/N.
     *
     * @param string $inputName Имя HTML-поля.
     * @param mixed $rawValue Текущее значение свойства.
     * @param array<int, array{VALUE: string, TEXT: string}> $options Варианты значений.
     *
     * @return string HTML checkbox-контролов.
     */
    private static function renderCheckboxes(string $inputName, $rawValue, array $options): string
    {
        if ($options === []) {
            $checked = (string)$rawValue === 'Y' ? ' checked' : '';

            return '<input type="hidden" name="' . $inputName . '" value="N">'
                . '<input type="checkbox" name="' . $inputName . '" value="Y"' . $checked . '>';
        }

        $selectedValues = self::normalizeValueList($rawValue);
        $html = '<input type="hidden" name="' . $inputName . '[]" value="">';

        foreach ($options as $option) {
            $optionValue = self::getOptionValue($option);
            $optionText = self::getOptionText($option);
            $checked = in_array($optionValue, $selectedValues, true) ? ' checked' : '';

            $html .= '<label style="display:block;margin-bottom:4px;">'
                . '<input type="checkbox" name="' . $inputName . '[]" value="' . htmlspecialcharsbx($optionValue) . '"' . $checked . '> '
                . htmlspecialcharsbx($optionText)
                . '</label>';
        }

        return $html;
    }

    /**
     * Рендерит группу radio-кнопок для значения свойства.
     *
     * @param string $inputName Имя HTML-поля.
     * @param string $selectedValue Текущее выбранное значение.
     * @param array<int, array{VALUE: string, TEXT: string}> $options Варианты значений.
     *
     * @return string HTML radio-контролов.
     */
    private static function renderRadios(string $inputName, string $selectedValue, array $options): string
    {
        $html = '<input type="hidden" name="' . $inputName . '" value="">';

        foreach ($options as $option) {
            $optionValue = self::getOptionValue($option);
            $optionText = self::getOptionText($option);
            $checked = $selectedValue === $optionValue ? ' checked' : '';

            $html .= '<label style="display:block;margin-bottom:4px;">'
                . '<input type="radio" name="' . $inputName . '" value="' . htmlspecialcharsbx($optionValue) . '"' . $checked . '> '
                . htmlspecialcharsbx($optionText)
                . '</label>';
        }

        return $html;
    }

    /**
     * Нормализует варианты значений из настроек свойства.
     *
     * Поддерживает старый текстовый формат и текущий массив пар VALUE/TEXT.
     *
     * @param mixed $options Сырые варианты значений.
     *
     * @return array<int, array{VALUE: string, TEXT: string}>
     */
    private static function prepareOptions($options): array
    {
        if (is_string($options)) {
            $options = preg_split('/\r\n|\r|\n/', $options);
        }

        if (!is_array($options)) {
            return [];
        }

        $result = [];

        foreach ($options as $option) {
            if (is_array($option)) {
                $value = trim((string)($option['VALUE'] ?? $option['value'] ?? ''));
                $text = trim((string)($option['TEXT'] ?? $option['text'] ?? ''));
            } else {
                $value = trim((string)$option);
                $text = $value;
            }

            if ($value === '' && $text === '') {
                continue;
            }

            if ($value === '') {
                $value = $text;
            }

            if ($text === '') {
                $text = $value;
            }

            $result[] = [
                'VALUE' => $value,
                'TEXT' => $text,
            ];
        }

        return $result;
    }

    /**
     * Рендерит строки настройки вариантов значений.
     *
     * @param string $settingsName Базовое имя HTML-полей настроек.
     * @param array<int, array{VALUE: string, TEXT: string}> $options Варианты значений.
     *
     * @return string HTML строк вариантов.
     */
    private static function renderOptionSettingsRows(string $settingsName, array $options): string
    {
        if ($options === []) {
            $options = [
                [
                    'VALUE' => '',
                    'TEXT' => '',
                ],
            ];
        }

        $html = '';

        foreach ($options as $index => $option) {
            $html .= self::renderOptionSettingsRow($settingsName, (int)$index, $option);
        }

        return $html;
    }

    /**
     * Рендерит одну строку настройки варианта значения.
     *
     * @param string $settingsName Базовое имя HTML-полей настроек.
     * @param int $index Индекс варианта.
     * @param array{VALUE: string, TEXT: string} $option Вариант значения.
     *
     * @return string HTML строки варианта.
     */
    private static function renderOptionSettingsRow(string $settingsName, int $index, array $option): string
    {
        $removeOptionHandler = htmlspecialcharsbx(self::getRemoveOptionHandler());

        return '<div class="webcomp-forms-property-settings__option" style="display:flex;align-items:center;gap:8px;margin-bottom:8px;" data-webcomp-forms-option-row>'
            . '<input type="text" class="webcomp-forms-property-settings__option-input webcomp-forms-property-settings__option-input--value" style="width:170px;" name="' . $settingsName . '[OPTIONS][' . $index . '][VALUE]" value="' . htmlspecialcharsbx(self::getOptionValue($option)) . '" placeholder="Значение">'
            . '<input type="text" class="webcomp-forms-property-settings__option-input webcomp-forms-property-settings__option-input--text" style="width:210px;" name="' . $settingsName . '[OPTIONS][' . $index . '][TEXT]" value="' . htmlspecialcharsbx(self::getOptionText($option)) . '" placeholder="Текст">'
            . '<button type="button" class="adm-btn" style="white-space:nowrap;" data-webcomp-forms-remove-option onclick="' . $removeOptionHandler . '">Удалить</button>'
            . '</div>';
    }

    /**
     * Возвращает inline-JS переключения видимости настроек вариантов.
     *
     * @return string JavaScript-обработчик onchange.
     */
    private static function getFieldTypeChangeHandler(): string
    {
        return <<<'JS'
        (function (select) {
            var table = select.closest('table') || document;
            var row = table.querySelector('[data-webcomp-forms-options-row]');

            if (row) {
                row.style.display = ({select: 1, checkbox: 1, radio: 1})[select.value] ? '' : 'none';
            }
        })(this);
        JS;
    }

    /**
     * Возвращает inline-JS добавления строки варианта значения.
     *
     * @return string JavaScript-обработчик onclick.
     */
    private static function getAddOptionHandler(): string
    {
        return <<<'JS'
        (function (button) {
            var cell = button.closest('td');
            var container = cell && cell.querySelector('[data-webcomp-forms-options]');

            if (!container) {
                return false;
            }

            var baseName = container.getAttribute('data-name');
            var index = parseInt(container.getAttribute('data-next-index') || '0', 10);

            var row = document.createElement('div');
            row.className = 'webcomp-forms-property-settings__option';
            row.style.cssText = 'display:flex;align-items:center;gap:8px;margin-bottom:8px;';
            row.setAttribute('data-webcomp-forms-option-row', '');

            var valueInput = document.createElement('input');
            valueInput.type = 'text';
            valueInput.className = 'webcomp-forms-property-settings__option-input webcomp-forms-property-settings__option-input--value';
            valueInput.style.width = '170px';
            valueInput.name = baseName + '[' + index + '][VALUE]';
            valueInput.placeholder = 'Значение';

            var textInput = document.createElement('input');
            textInput.type = 'text';
            textInput.className = 'webcomp-forms-property-settings__option-input webcomp-forms-property-settings__option-input--text';
            textInput.style.width = '210px';
            textInput.name = baseName + '[' + index + '][TEXT]';
            textInput.placeholder = 'Текст';

            var removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className = 'adm-btn';
            removeButton.style.whiteSpace = 'nowrap';
            removeButton.setAttribute('data-webcomp-forms-remove-option', '');
            removeButton.textContent = 'Удалить';
            removeButton.onclick = function () {
                var removeButton = this;
                var row = removeButton.closest('[data-webcomp-forms-option-row]');
                var container = row && row.closest('[data-webcomp-forms-options]');

                if (!row || !container) {
                    return false;
                }

                var rows = container.querySelectorAll('[data-webcomp-forms-option-row]');

                if (rows.length <= 1) {
                    var inputs = row.querySelectorAll('input');

                    for (var i = 0; i < inputs.length; i++) {
                        inputs[i].value = '';
                    }

                    return false;
                }

                row.parentNode.removeChild(row);

                return false;
            };

            row.appendChild(valueInput);
            row.appendChild(textInput);
            row.appendChild(removeButton);
            container.appendChild(row);
            container.setAttribute('data-next-index', String(index + 1));

            return false;
        })(this);
        JS;
    }

    /**
     * Возвращает inline-JS удаления строки варианта значения.
     *
     * @return string JavaScript-обработчик onclick.
     */
    private static function getRemoveOptionHandler(): string
    {
        return <<<'JS'
        (function (button) {
            var row = button.closest('[data-webcomp-forms-option-row]');
            var container = row && row.closest('[data-webcomp-forms-options]');

            if (!row || !container) {
                return false;
            }

            var rows = container.querySelectorAll('[data-webcomp-forms-option-row]');

            if (rows.length <= 1) {
                var inputs = row.querySelectorAll('input');

                for (var i = 0; i < inputs.length; i++) {
                    inputs[i].value = '';
                }

                return false;
            }

            row.parentNode.removeChild(row);

            return false;
        })(this);
        JS;
    }

    /**
     * Возвращает техническое значение варианта.
     *
     * @param array $option Вариант значения.
     *
     * @return string
     */
    private static function getOptionValue(array $option): string
    {
        return (string)($option['VALUE'] ?? '');
    }

    /**
     * Возвращает текст варианта для показа пользователю.
     *
     * @param array $option Вариант значения.
     *
     * @return string
     */
    private static function getOptionText(array $option): string
    {
        return (string)($option['TEXT'] ?? '');
    }

    /**
     * Заменяет технические значения на тексты вариантов.
     *
     * @param array<int, string> $values Значения свойства.
     * @param array<int, array{VALUE: string, TEXT: string}> $options Варианты значений.
     *
     * @return array<int, string>
     */
    private static function mapValuesToOptionTexts(array $values, array $options): array
    {
        if ($options === []) {
            return $values;
        }

        $map = [];

        foreach ($options as $option) {
            $map[self::getOptionValue($option)] = self::getOptionText($option);
        }

        return array_map(
            static fn($value): string => $map[$value] ?? $value,
            $values
        );
    }

    /**
     * Приводит значение свойства к списку строк.
     *
     * Поддерживает массив, JSON-строку массива и одиночное строковое значение.
     *
     * @param mixed $rawValue Сырое значение свойства.
     *
     * @return array<int, string>
     */
    private static function normalizeValueList($rawValue): array
    {
        if (is_array($rawValue)) {
            $values = $rawValue;
        } else {
            $rawValue = trim((string)$rawValue);
            $decoded = json_decode($rawValue, true);
            $values = is_array($decoded) ? $decoded : ($rawValue === '' ? [] : [$rawValue]);
        }

        return array_values(array_filter(array_map(
            static fn($item): string => trim((string)$item),
            $values
        ), static fn($item): bool => $item !== ''));
    }

    /**
     * Подключает CSS административного поля один раз за запрос.
     *
     * @return void
     */
    private static function includeAssets(): void
    {
        if (self::$assetsIncluded) {
            return;
        }

        $asset = Asset::getInstance();
        $asset->addString(
            '<link rel="stylesheet" type="text/css" href="' . htmlspecialcharsbx(\CUtil::GetAdditionalFileURL(self::ASSET_CSS, true)) . '">',
            true
        );

        self::$assetsIncluded = true;
    }

    /**
     * Возвращает безопасный HTML type для input.
     *
     * @param string $fieldType Тип поля из настроек свойства.
     *
     * @return string
     */
    private static function getInputHtmlType(string $fieldType): string
    {
        return in_array($fieldType, ['email', 'tel', 'url', 'number', 'date'], true)
            ? $fieldType
            : 'text';
    }

    /**
     * Возвращает список доступных типов публичного поля формы.
     *
     * @return array<string, string>
     */
    private static function getFieldTypes(): array
    {
        return [
            'text' => 'Текст',
            'email' => 'E-mail',
            'tel' => 'Телефон',
            'url' => 'Ссылка',
            'number' => 'Число',
            'date' => 'Дата',
            'textarea' => 'Многострочный текст',
            'select' => 'Выпадающий список',
            'checkbox' => 'Флажок',
            'radio' => 'Переключатель',
        ];
    }
}
