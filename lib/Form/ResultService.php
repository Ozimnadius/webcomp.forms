<?php

namespace Webcomp\Forms\Form;

use Bitrix\Main\Loader;
use Bitrix\Main\Mail\Event;
use RuntimeException;
use Throwable;

/**
 * Обрабатывает отправку публичной формы.
 *
 * Сервис проверяет обязательные поля, сохраняет результат как элемент
 * инфоблока формы и ставит в очередь почтовое событие формы. Компонент
 * передает сюда уже нормализованные поля из FormRepository и данные запроса.
 *
 * TODO: добавить отдельную обработку file-полей: прием $_FILES, проверку
 * размера/типа, сохранение файлов и передачу вложений в почтовое событие.
 */
class ResultService
{
    private const SYSTEM_ERROR_CODE = 'SYSTEM';
    private const REQUIRED_ERROR = 'Поле обязательно для заполнения';
    private const INVALID_VALUE_ERROR = 'Некорректное значение поля';
    private const MAX_VALUE_LENGTH = 5000;
    private const TOO_LONG_ERROR = 'Слишком длинное значение поля';

    /**
     * Выполняет полный цикл обработки отправки формы.
     *
     * @param int $iblockId ID инфоблока формы.
     * @param array<int, array<string, mixed>> $fields Нормализованные поля формы.
     * @param array<string, mixed> $requestData Данные POST-запроса.
     * @param array<string, mixed> $context Контекст формы и сайта.
     *
     * @return array{SUCCESS: bool, RESULT_ID: int, ERRORS: array<string, string>}
     */
    public function handle(int $iblockId, array $fields, array $requestData, array $context): array
    {
        $errors = $this->validate($fields, $requestData);

        if ($errors !== []) {
            return $this->buildResult(false, 0, $errors);
        }

        try {
            $resultId = $this->saveResult($iblockId, $fields, $requestData);
        } catch (Throwable $exception) {
            $this->logException($exception);

            return $this->buildResult(false, 0, [
                self::SYSTEM_ERROR_CODE => 'Не удалось отправить форму',
            ]);
        }

        try {
            $this->sendMail($iblockId, $resultId, $fields, $requestData, $context);
        } catch (Throwable $exception) {
            // Заявка уже сохранена: сбой почтового уведомления не должен выглядеть
            // для посетителя как ошибка отправки и провоцировать повторные сабмиты.
            $this->logException($exception);
        }

        return $this->buildResult(true, $resultId, []);
    }

    /**
     * Проверяет данные отправленной формы.
     *
     * Проверяются обязательные поля, а для select/radio/checkbox с OPTIONS -
     * принадлежность отправленного значения списку допустимых вариантов.
     *
     * @param array<int, array<string, mixed>> $fields Нормализованные поля формы.
     * @param array<string, mixed> $requestData Данные POST-запроса.
     *
     * @return array<string, string> Ошибки по кодам полей.
     */
    public function validate(array $fields, array $requestData): array
    {
        $errors = [];

        foreach ($fields as $field) {
            $code = $this->getFieldCode($field);

            if ($code === '') {
                continue;
            }

            $value = $requestData[$code] ?? null;

            if (($field['REQUIRED'] ?? false) === true && $this->isEmptyValue($field, $value)) {
                $errors[$code] = self::REQUIRED_ERROR;
                continue;
            }

            if (!$this->isEmptyValue($field, $value) && !$this->isAllowedOptionValue($field, $value)) {
                $errors[$code] = self::INVALID_VALUE_ERROR;
            }

            if (!isset($errors[$code]) && $this->isTooLongValue($value)) {
                $errors[$code] = self::TOO_LONG_ERROR;
            }
        }

        return $errors;
    }

    /**
     * Проверяет, превышает ли какое-либо из значений поля допустимую длину.
     *
     * @param mixed $value Значение из запроса.
     *
     * @return bool
     */
    private function isTooLongValue($value): bool
    {
        foreach ($this->normalizeValueList($value) as $item) {
            if (mb_strlen($item) > self::MAX_VALUE_LENGTH) {
                return true;
            }
        }

        return false;
    }

    /**
     * Сохраняет результат формы как элемент инфоблока формы.
     *
     * @param int $iblockId ID инфоблока формы.
     * @param array<int, array<string, mixed>> $fields Нормализованные поля формы.
     * @param array<string, mixed> $requestData Данные POST-запроса.
     *
     * @return int ID созданного элемента результата.
     */
    public function saveResult(int $iblockId, array $fields, array $requestData): int
    {
        if ($iblockId <= 0 || !Loader::includeModule('iblock')) {
            throw new RuntimeException('Модуль iblock не подключен или указан некорректный ID инфоблока.');
        }

        $element = new \CIBlockElement();
        $resultId = (int)$element->Add([
            'IBLOCK_ID' => $iblockId,
            'ACTIVE' => 'Y',
            'NAME' => 'Результат формы от ' . ConvertTimeStamp(time(), 'FULL'),
            'PROPERTY_VALUES' => $this->buildPropertyValues($fields, $requestData),
        ]);

        if ($resultId <= 0) {
            throw new RuntimeException((string)($element->LAST_ERROR ?: 'Не удалось сохранить результат формы.'));
        }

        return $resultId;
    }

    /**
     * Ставит в очередь почтовое событие формы.
     *
     * @param int $iblockId ID инфоблока формы.
     * @param int $resultId ID элемента результата.
     * @param array<int, array<string, mixed>> $fields Нормализованные поля формы.
     * @param array<string, mixed> $requestData Данные POST-запроса.
     * @param array<string, mixed> $context Контекст формы и сайта.
     *
     * @return void
     */
    public function sendMail(int $iblockId, int $resultId, array $fields, array $requestData, array $context): void
    {
        $form = $this->getFormFromContext($iblockId, $context);

        if ($form === null) {
            throw new RuntimeException('Форма для отправки письма не найдена.');
        }

        $siteId = $this->getSiteId($form, $context);
        $mailResult = Event::send([
            'EVENT_NAME' => MailEventSynchronizer::getEventName($form),
            'LID' => $siteId,
            'C_FIELDS' => $this->buildMailFields($form, $resultId, $fields, $requestData, $context),
        ]);

        if (!$mailResult->isSuccess()) {
            $errorMessages = array_map(
                static fn($error): string => $error->getMessage(),
                $mailResult->getErrors()
            );

            throw new RuntimeException(implode('; ', $errorMessages) ?: 'Не удалось поставить почтовое событие в очередь.');
        }
    }

    /**
     * Возвращает код поля формы.
     *
     * @param array<string, mixed> $field Поле формы.
     *
     * @return string
     */
    private function getFieldCode(array $field): string
    {
        return trim((string)($field['CODE'] ?? ''));
    }

    /**
     * Проверяет, считается ли значение пустым для конкретного типа поля.
     *
     * @param array<string, mixed> $field Поле формы.
     * @param mixed $value Значение из запроса.
     *
     * @return bool
     */
    private function isEmptyValue(array $field, $value): bool
    {
        if (($field['TYPE'] ?? '') === 'checkbox' && empty($field['OPTIONS'])) {
            return $value !== 'Y';
        }

        if (is_array($value)) {
            return $this->normalizeValueList($value) === [];
        }

        return trim((string)$value) === '';
    }

    /**
     * Проверяет значение поля по списку допустимых OPTIONS.
     *
     * @param array<string, mixed> $field Поле формы.
     * @param mixed $value Значение из запроса.
     *
     * @return bool
     */
    private function isAllowedOptionValue(array $field, $value): bool
    {
        $fieldType = (string)($field['TYPE'] ?? '');
        $options = is_array($field['OPTIONS'] ?? null) ? $field['OPTIONS'] : [];

        if (!in_array($fieldType, ['select', 'radio', 'checkbox'], true) || $options === []) {
            return true;
        }

        $allowedValues = [];

        foreach ($options as $option) {
            $optionValue = trim((string)($option['VALUE'] ?? ''));

            if ($optionValue !== '') {
                $allowedValues[$optionValue] = true;
            }
        }

        if ($allowedValues === []) {
            return true;
        }

        foreach ($this->normalizeValueList($value) as $submittedValue) {
            if (!isset($allowedValues[$submittedValue])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Формирует значения свойств для CIBlockElement::Add.
     *
     * @param array<int, array<string, mixed>> $fields Нормализованные поля формы.
     * @param array<string, mixed> $requestData Данные POST-запроса.
     *
     * @return array<string, string>
     */
    private function buildPropertyValues(array $fields, array $requestData): array
    {
        $propertyValues = [];

        foreach ($fields as $field) {
            $code = $this->getFieldCode($field);

            if ($code === '') {
                continue;
            }

            $propertyValues[$code] = $this->prepareValueForStorage($field, $requestData[$code] ?? null);
        }

        return $propertyValues;
    }

    /**
     * Подготавливает значение для сохранения в свойство formbuilder.
     *
     * @param array<string, mixed> $field Поле формы.
     * @param mixed $value Значение из запроса.
     *
     * @return string
     */
    private function prepareValueForStorage(array $field, $value): string
    {
        if (($field['TYPE'] ?? '') === 'checkbox' && empty($field['OPTIONS'])) {
            return $value === 'Y' ? 'Y' : 'N';
        }

        if (is_array($value)) {
            $values = $this->normalizeValueList($value);

            return $values === []
                ? ''
                : (string)json_encode($values, JSON_UNESCAPED_UNICODE);
        }

        return trim((string)$value);
    }

    /**
     * Возвращает данные формы из контекста или читает их из репозитория.
     *
     * @param int $iblockId ID инфоблока формы.
     * @param array<string, mixed> $context Контекст обработки.
     *
     * @return array<string, mixed>|null
     *
     * @throws \Exception При ошибке чтения формы через ORM.
     */
    private function getFormFromContext(int $iblockId, array $context): ?array
    {
        if (isset($context['FORM']) && is_array($context['FORM'])) {
            return $context['FORM'];
        }

        return (new FormRepository())->getFormById($iblockId);
    }

    /**
     * Определяет сайт, от имени которого отправляется почтовое событие.
     *
     * @param array<string, mixed> $form Данные формы.
     * @param array<string, mixed> $context Контекст обработки.
     *
     * @return string
     */
    private function getSiteId(array $form, array $context): string
    {
        $siteId = trim((string)($context['SITE_ID'] ?? ''));

        if ($siteId !== '') {
            return $siteId;
        }

        $siteIds = is_array($form['SITE_IDS'] ?? null) ? $form['SITE_IDS'] : [];
        $siteId = trim((string)($siteIds[0] ?? ''));

        return $siteId !== '' ? $siteId : (defined('SITE_ID') ? SITE_ID : 's1');
    }

    /**
     * Формирует массив макросов для почтового события.
     *
     * @param array<string, mixed> $form Данные формы.
     * @param int $resultId ID элемента результата.
     * @param array<int, array<string, mixed>> $fields Нормализованные поля формы.
     * @param array<string, mixed> $requestData Данные POST-запроса.
     * @param array<string, mixed> $context Контекст обработки.
     *
     * @return array<string, string>
     */
    private function buildMailFields(array $form, int $resultId, array $fields, array $requestData, array $context): array
    {
        $iblockId = (int)($form['ID'] ?? 0);
        $serverName = $this->getServerName($context);
        $eventFields = [
            'FORM_NAME' => (string)($form['NAME'] ?? ''),
            'FORM_CODE' => (string)($form['CODE'] ?? ''),
            'FORM_ID' => (string)$iblockId,
            'RESULT_ID' => (string)$resultId,
            'DATE_CREATE' => ConvertTimeStamp(time(), 'FULL'),
            'SITE_NAME' => $this->getSiteName($this->getSiteId($form, $context), $context),
            'SERVER_NAME' => $serverName,
            'ADMIN_RESULT_URL' => $this->buildAdminResultUrl($serverName, $iblockId, $resultId),
        ];

        foreach ($fields as $field) {
            $code = $this->getFieldCode($field);

            if ($code === '') {
                continue;
            }

            $eventFields[$code] = $this->formatValueForMail($field, $requestData[$code] ?? null);
        }

        return $eventFields;
    }

    /**
     * Определяет домен сайта для макросов письма.
     *
     * @param array<string, mixed> $context Контекст обработки.
     *
     * @return string
     */
    private function getServerName(array $context): string
    {
        $serverName = trim((string)($context['SERVER_NAME'] ?? ''));

        if ($serverName !== '') {
            return $serverName;
        }

        return trim((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
    }

    /**
     * Определяет название сайта для макроса SITE_NAME.
     *
     * @param string $siteId ID сайта.
     * @param array<string, mixed> $context Контекст обработки.
     *
     * @return string
     */
    private function getSiteName(string $siteId, array $context): string
    {
        $siteName = trim((string)($context['SITE_NAME'] ?? ''));

        if ($siteName !== '') {
            return $siteName;
        }

        if ($siteId !== '') {
            $site = \CSite::GetByID($siteId)->Fetch();

            if (is_array($site)) {
                $siteName = trim((string)($site['SITE_NAME'] ?? $site['NAME'] ?? ''));

                if ($siteName !== '') {
                    return $siteName;
                }
            }
        }

        return $siteId;
    }

    /**
     * Формирует абсолютную ссылку на элемент результата в админке.
     *
     * @param string $serverName Домен сайта.
     * @param int $iblockId ID инфоблока формы.
     * @param int $resultId ID элемента результата.
     *
     * @return string
     */
    private function buildAdminResultUrl(string $serverName, int $iblockId, int $resultId): string
    {
        $path = '/bitrix/admin/iblock_element_edit.php?'
            . http_build_query([
                'IBLOCK_ID' => $iblockId,
                'type' => IblockTypeInstaller::TYPE_ID,
                'ID' => $resultId,
                'lang' => defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru',
                'find_section_section' => -1,
                'WF' => 'Y',
            ]);

        if ($serverName === '') {
            return $path;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';

        return $scheme . '://' . $serverName . $path;
    }

    /**
     * Форматирует значение поля для письма.
     *
     * @param array<string, mixed> $field Поле формы.
     * @param mixed $value Значение из запроса.
     *
     * @return string
     */
    private function formatValueForMail(array $field, $value): string
    {
        if (($field['TYPE'] ?? '') === 'checkbox' && empty($field['OPTIONS'])) {
            return $value === 'Y' ? 'Да' : 'Нет';
        }

        $values = $this->normalizeValueList($value);

        if ($values === []) {
            return '';
        }

        $optionMap = $this->getOptionTextMap($field);

        if ($optionMap !== []) {
            $values = array_map(
                static fn(string $item): string => $optionMap[$item] ?? $item,
                $values
            );
        }

        return nl2br(htmlspecialcharsbx(implode(', ', $values)));
    }

    /**
     * Возвращает карту VALUE => TEXT для вариантов поля.
     *
     * @param array<string, mixed> $field Поле формы.
     *
     * @return array<string, string>
     */
    private function getOptionTextMap(array $field): array
    {
        $map = [];
        $options = is_array($field['OPTIONS'] ?? null) ? $field['OPTIONS'] : [];

        foreach ($options as $option) {
            $value = trim((string)($option['VALUE'] ?? ''));
            $text = trim((string)($option['TEXT'] ?? ''));

            if ($value !== '') {
                $map[$value] = $text !== '' ? $text : $value;
            }
        }

        return $map;
    }

    /**
     * Приводит значение из запроса к списку непустых строк.
     *
     * @param mixed $value Значение из запроса.
     *
     * @return array<int, string>
     */
    private function normalizeValueList($value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_filter(array_map(
            static fn($item): string => trim((string)$item),
            $values
        ), static fn(string $item): bool => $item !== ''));
    }

    /**
     * Возвращает единый результат обработки формы.
     *
     * @param bool $success Флаг успешной обработки.
     * @param int $resultId ID созданного результата.
     * @param array<string, string> $errors Ошибки обработки.
     *
     * @return array{SUCCESS: bool, RESULT_ID: int, ERRORS: array<string, string>}
     */
    private function buildResult(bool $success, int $resultId, array $errors): array
    {
        return [
            'SUCCESS' => $success,
            'RESULT_ID' => $resultId,
            'ERRORS' => $errors,
        ];
    }

    /**
     * Записывает системную ошибку обработки формы в журнал Bitrix.
     *
     * @param Throwable $exception Перехваченное исключение или ошибка.
     *
     * @return void
     */
    private function logException(Throwable $exception): void
    {
        if (function_exists('AddMessage2Log')) {
            AddMessage2Log($exception->getMessage(), 'webcomp.forms');
        }
    }
}
