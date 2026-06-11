<?php

use Bitrix\Main\Context;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Webcomp\Forms\Form\FormRepository;
use Webcomp\Forms\Form\ResultService;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

/**
 * Компонент публичной формы на базе инфоблока типа forms.
 *
 * Компонент отвечает за выбор формы, подготовку данных для шаблона,
 * проверку служебных параметров отправки и передачу заполненных значений
 * в сервис сохранения результата. Визуальная разметка, тексты успешной
 * и ошибочной отправки остаются ответственностью шаблона компонента.
 *
 * Поддерживает два режима отображения (DISPLAY_MODE): inline - форма прямо
 * на странице, popup - на странице только кнопка и пустой dialog, а HTML
 * формы лениво загружается отдельным запросом при первом открытии попапа.
 */
class WebcompFormComponent extends CBitrixComponent
{
    private const SUBMIT_FIELD = 'webcomp_form_submit';
    private const RENDER_FIELD = 'webcomp_form_render';
    private const PARAMS_HASH_FIELD = 'PARAMS_HASH';
    private const SUCCESS_FLAG_FIELD = 'webcomp_form_success';
    private const DISPLAY_INLINE = 'inline';
    private const DISPLAY_POPUP = 'popup';
    private const DEFAULT_SUBMIT_TEXT = 'Отправить';
    private const DEFAULT_BUTTON_TEXT = 'Открыть форму';
    private const DEFAULT_BUTTON_CLASS = 'btn btn-primary';

    /**
     * Нормализует параметры компонента до стабильного формата.
     *
     * @param array $arParams Параметры, переданные в IncludeComponent.
     *
     * @return array
     */
    public function onPrepareComponentParams($arParams): array
    {
        $arParams['IBLOCK_ID'] = (int)($arParams['IBLOCK_ID'] ?? 0);
        $arParams['IBLOCK_CODE'] = trim((string)($arParams['IBLOCK_CODE'] ?? ''));
        $arParams['USE_AJAX'] = (($arParams['USE_AJAX'] ?? 'Y') === 'Y') ? 'Y' : 'N';

        $submitText = trim((string)($arParams['SUBMIT_TEXT'] ?? ''));
        $arParams['SUBMIT_TEXT'] = $submitText !== '' ? $submitText : self::DEFAULT_SUBMIT_TEXT;

        $arParams['DISPLAY_MODE'] = ($arParams['DISPLAY_MODE'] ?? '') === self::DISPLAY_POPUP
            ? self::DISPLAY_POPUP
            : self::DISPLAY_INLINE;

        if ($arParams['DISPLAY_MODE'] === self::DISPLAY_POPUP) {
            // В попапе сообщения рисует JS; обычный POST остается только
            // fallback-сценарием без JS и деградирует до инлайн-страницы.
            $arParams['USE_AJAX'] = 'Y';
        }

        $buttonText = trim((string)($arParams['BUTTON_TEXT'] ?? ''));
        $arParams['BUTTON_TEXT'] = $buttonText !== '' ? $buttonText : self::DEFAULT_BUTTON_TEXT;

        $buttonClass = trim((string)($arParams['BUTTON_CLASS'] ?? ''));
        $arParams['BUTTON_CLASS'] = $buttonClass !== '' ? $buttonClass : self::DEFAULT_BUTTON_CLASS;

        return $arParams;
    }

    /**
     * Выполняет компонент.
     *
     * @return void
     */
    public function executeComponent(): void
    {
        if (!Loader::includeModule('webcomp.forms')) {
            ShowError('Модуль webcomp.forms не подключен.');
            return;
        }

        if ($this->isFragmentRequest()) {
            $this->renderFragment();
            return;
        }

        $isSubmitted = $this->isRawSubmitRequest();

        if (!$isSubmitted) {
            $this->renderForm();
            return;
        }

        $this->handleSubmit();
    }

    /**
     * Рендерит форму.
     *
     * Вывод не кэшируется: в шаблоне присутствует bitrix_sessid_post(),
     * который должен формироваться для текущей сессии посетителя.
     *
     * @return void
     */
    private function renderForm(): void
    {
        if ($this->arParams['DISPLAY_MODE'] === self::DISPLAY_POPUP) {
            $this->renderPopupShell();
            return;
        }

        $data = $this->loadFormData();

        if ($data === null) {
            ShowError('Форма не найдена.');
            return;
        }

        [$form, $fields] = $data;

        $this->arResult = $this->buildArResult(
            $form,
            $fields,
            $this->getEmptyValues($fields),
            [],
            $this->getDefaultSubmitResult(),
            false
        );
        $this->arResult['RENDER_MODE'] = 'inline';

        $this->includeComponentTemplate();
    }

    /**
     * Рендерит каркас попапа: кнопку-триггер и пустой dialog.
     *
     * Запросы к инфоблоку не выполняются - форма приедет HTML-фрагментом при
     * первом открытии попапа. Единственное исключение - возврат с маркером
     * успешной fallback-отправки, когда форма читается для сверки ID.
     *
     * @return void
     */
    private function renderPopupShell(): void
    {
        $this->arResult = [
            'FORM' => [],
            'FIELDS' => [],
            'VALUES' => [],
            'ERRORS' => [],
            'SUBMIT_RESULT' => $this->getDefaultSubmitResult(),
            'SHOW_SUCCESS' => $this->shouldShowShellSuccess(),
            'PARAMS_HASH' => $this->getParamsHash(),
            'USE_AJAX' => $this->arParams['USE_AJAX'],
            'SUBMIT_TEXT' => $this->arParams['SUBMIT_TEXT'],
            'IS_SUBMITTED' => false,
            'RENDER_MODE' => 'popup_shell',
            'BUTTON_TEXT' => $this->arParams['BUTTON_TEXT'],
            'BUTTON_CLASS' => $this->arParams['BUTTON_CLASS'],
        ];

        $this->includeComponentTemplate();
    }

    /**
     * Отдает HTML-фрагмент формы для попапа и завершает запрос.
     *
     * Фрагмент рендерится в сессии текущего посетителя и содержит актуальный
     * sessid, поэтому страница с кнопкой может кэшироваться целиком - защита
     * отправки от этого не страдает.
     *
     * @return void
     */
    private function renderFragment(): void
    {
        global $APPLICATION;

        $APPLICATION->RestartBuffer();
        header('Content-Type: text/html; charset=UTF-8');

        $data = $this->loadFormData();

        if ($data === null) {
            echo '<div class="alert alert-danger" role="alert">Форма не найдена.</div>';
        } else {
            [$form, $fields] = $data;

            $this->arResult = $this->buildArResult(
                $form,
                $fields,
                $this->getEmptyValues($fields),
                [],
                $this->getDefaultSubmitResult(),
                false
            );
            $this->arResult['RENDER_MODE'] = 'fragment';

            $this->includeComponentTemplate();
        }

        CMain::FinalActions();
        die();
    }

    /**
     * Проверяет, что текущий запрос - запрос HTML-фрагмента формы для попапа.
     *
     * Фрагмент не изменяет данные, поэтому sessid не требуется: проверяется
     * только принадлежность запроса этому вызову компонента через PARAMS_HASH.
     *
     * @return bool
     */
    private function isFragmentRequest(): bool
    {
        if ($this->arParams['DISPLAY_MODE'] !== self::DISPLAY_POPUP) {
            return false;
        }

        $request = Context::getCurrent()->getRequest();

        return (string)$request->get(self::RENDER_FIELD) === 'Y'
            && (string)$request->get(self::PARAMS_HASH_FIELD) === $this->getParamsHash();
    }

    /**
     * Определяет показ баннера успеха у кнопки попапа после fallback-отправки.
     *
     * @return bool
     */
    private function shouldShowShellSuccess(): bool
    {
        $flag = (int)Context::getCurrent()->getRequest()->get(self::SUCCESS_FLAG_FIELD);

        if ($flag <= 0) {
            return false;
        }

        $data = $this->loadFormData();

        return $data !== null && (int)($data[0]['ID'] ?? 0) === $flag;
    }

    /**
     * Обрабатывает отправку формы.
     *
     * Успешная не-AJAX отправка завершается редиректом на текущую страницу
     * с маркером успеха (Post/Redirect/Get), чтобы обновление страницы не
     * повторяло POST. AJAX-отправка получает JSON-ответ без редиректа.
     *
     * @return void
     */
    private function handleSubmit(): void
    {
        $data = $this->loadFormData();

        if ($data === null) {
            ShowError('Форма не найдена.');
            return;
        }

        [$form, $fields] = $data;

        $paramsHash = $this->getParamsHash();
        $values = $this->getRequestData($fields);
        $errors = [];
        $submitResult = $this->getDefaultSubmitResult();

        if (!$this->isValidSubmitRequest($paramsHash)) {
            $errors = ['SYSTEM' => 'Некорректная отправка формы.'];
            $submitResult['ERRORS'] = $errors;
        } else {
            $submitResult = (new ResultService())->handle(
                (int)$form['ID'],
                $fields,
                $values,
                $this->getSubmitContext($form)
            );

            $errors = $submitResult['ERRORS'] ?? [];

            if (($submitResult['SUCCESS'] ?? false) === true) {
                $values = $this->getEmptyValues($fields);
            }
        }

        if (($submitResult['SUCCESS'] ?? false) === true && !$this->isAjaxResponseRequired()) {
            LocalRedirect($this->getSuccessUrl((int)$form['ID']));
        }

        $this->arResult = $this->buildArResult($form, $fields, $values, $errors, $submitResult, true);
        $this->arResult['RENDER_MODE'] = 'inline';

        if ($this->isAjaxResponseRequired()) {
            $this->sendAjaxResponse();
        }

        $this->includeComponentTemplate();
    }

    /**
     * Загружает форму и ее поля.
     *
     * @return array|null Массив вида [form, fields] или null, если форма не найдена.
     */
    private function loadFormData(): ?array
    {
        $repository = new FormRepository();
        $form = null;

        if ($this->arParams['IBLOCK_ID'] > 0) {
            $form = $repository->getFormById((int)$this->arParams['IBLOCK_ID']);
        } elseif ($this->arParams['IBLOCK_CODE'] !== '') {
            $form = $repository->getFormByCode((string)$this->arParams['IBLOCK_CODE']);
        }

        if ($form === null) {
            return null;
        }

        return [
            $form,
            $repository->getFields((int)$form['ID']),
        ];
    }

    /**
     * Собирает итоговый arResult для шаблона.
     *
     * @param array $form Данные инфоблока формы.
     * @param array $fields Поля формы.
     * @param array $values Текущие значения полей.
     * @param array $errors Ошибки отправки.
     * @param array $submitResult Результат обработки отправки.
     * @param bool $isSubmitted Флаг отправки формы.
     *
     * @return array
     */
    private function buildArResult(
        array $form,
        array $fields,
        array $values,
        array $errors,
        array $submitResult,
        bool $isSubmitted
    ): array {
        return [
            'FORM' => $form,
            'FIELDS' => $fields,
            'VALUES' => $values,
            'ERRORS' => $errors,
            'SUBMIT_RESULT' => $submitResult,
            'SHOW_SUCCESS' => $this->shouldShowSuccess($form, $submitResult, $isSubmitted),
            'PARAMS_HASH' => $this->getParamsHash(),
            'USE_AJAX' => $this->arParams['USE_AJAX'],
            'SUBMIT_TEXT' => $this->arParams['SUBMIT_TEXT'],
            'IS_SUBMITTED' => $isSubmitted,
        ];
    }

    /**
     * Возвращает пустые значения для всех полей формы.
     *
     * @param array $fields Поля формы.
     *
     * @return array
     */
    private function getEmptyValues(array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            $code = (string)($field['CODE'] ?? '');

            if ($code === '') {
                continue;
            }

            $values[$code] = $this->isMultipleValueField($field) ? [] : '';
        }

        return $values;
    }

    /**
     * Возвращает значения полей из POST-запроса.
     *
     * @param array $fields Поля формы.
     *
     * @return array
     */
    private function getRequestData(array $fields): array
    {
        $requestData = Context::getCurrent()->getRequest()->getPostList()->toArray();
        $values = [];

        foreach ($fields as $field) {
            $code = (string)($field['CODE'] ?? '');

            if ($code === '') {
                continue;
            }

            if (array_key_exists($code, $requestData)) {
                $values[$code] = $this->normalizeRequestValue($field, $requestData[$code]);
                continue;
            }

            $values[$code] = $this->isMultipleValueField($field) ? [] : '';
        }

        return $values;
    }

    /**
     * Определяет, может ли поле принимать несколько значений.
     *
     * @param array $field Поле формы.
     *
     * @return bool
     */
    private function isMultipleValueField(array $field): bool
    {
        $type = (string)($field['TYPE'] ?? '');

        if ($type === 'checkbox' && !empty($field['OPTIONS'])) {
            return true;
        }

        return $type === 'select' && (($field['MULTIPLE'] ?? false) === true);
    }

    /**
     * Приводит значение из запроса к типу, ожидаемому полем.
     *
     * Поля с одним значением получают строку (массив из подменённого запроса
     * отбрасывается), поля с несколькими значениями — массив.
     *
     * @param array $field Поле формы.
     * @param mixed $value Сырое значение из POST.
     *
     * @return array|string
     */
    private function normalizeRequestValue(array $field, $value)
    {
        if ($this->isMultipleValueField($field)) {
            if (is_array($value)) {
                return $value;
            }

            return $value === null || $value === '' ? [] : [(string)$value];
        }

        return is_array($value) ? '' : (string)$value;
    }

    /**
     * Возвращает базовый результат отправки до обработки формы.
     *
     * @return array
     */
    private function getDefaultSubmitResult(): array
    {
        return [
            'SUCCESS' => false,
            'RESULT_ID' => 0,
            'ERRORS' => [],
        ];
    }

    /**
     * Формирует URL текущей страницы с маркером успешной отправки.
     *
     * @param int $formId ID инфоблока формы.
     *
     * @return string
     */
    private function getSuccessUrl(int $formId): string
    {
        global $APPLICATION;

        return $APPLICATION->GetCurPageParam(
            self::SUCCESS_FLAG_FIELD . '=' . $formId,
            [self::SUCCESS_FLAG_FIELD]
        );
    }

    /**
     * Определяет, нужно ли показывать сообщение об успешной отправке.
     *
     * Для POST-ответа берется результат обработки, для GET после редиректа -
     * маркер успешной отправки именно этой формы в параметрах запроса.
     *
     * @param array $form Данные инфоблока формы.
     * @param array $submitResult Результат обработки отправки.
     * @param bool $isSubmitted Флаг отправки формы.
     *
     * @return bool
     */
    private function shouldShowSuccess(array $form, array $submitResult, bool $isSubmitted): bool
    {
        if ($isSubmitted) {
            return ($submitResult['SUCCESS'] ?? false) === true;
        }

        $formId = (int)($form['ID'] ?? 0);
        $flag = (int)Context::getCurrent()->getRequest()->get(self::SUCCESS_FLAG_FIELD);

        return $formId > 0 && $flag === $formId;
    }

    /**
     * Формирует хэш параметров для защиты отправки от подмены формы.
     *
     * @return string
     */
    private function getParamsHash(): string
    {
        return md5(serialize([
            'IBLOCK_ID' => $this->arParams['IBLOCK_ID'],
            'IBLOCK_CODE' => $this->arParams['IBLOCK_CODE'],
        ]));
    }

    /**
     * Проверяет, что текущий запрос похож на отправку компонента.
     *
     * @return bool
     */
    private function isRawSubmitRequest(): bool
    {
        $request = Context::getCurrent()->getRequest();

        return $request->isPost() && (string)$request->getPost(self::SUBMIT_FIELD) === 'Y';
    }

    /**
     * Проверяет служебные параметры отправки формы.
     *
     * @param string $paramsHash Ожидаемый хэш параметров компонента.
     *
     * @return bool
     */
    private function isValidSubmitRequest(string $paramsHash): bool
    {
        $request = Context::getCurrent()->getRequest();

        return (string)$request->getPost(self::PARAMS_HASH_FIELD) === $paramsHash && check_bitrix_sessid();
    }

    /**
     * Проверяет, нужно ли вернуть AJAX-ответ.
     *
     * @return bool
     */
    private function isAjaxResponseRequired(): bool
    {
        return $this->arParams['USE_AJAX'] === 'Y' && Context::getCurrent()->getRequest()->isAjaxRequest();
    }

    /**
     * Возвращает контекст, который нужен сервису отправки письма.
     *
     * @param array $form Данные инфоблока формы.
     *
     * @return array
     */
    private function getSubmitContext(array $form): array
    {
        return [
            'FORM' => $form,
            'SITE_ID' => defined('SITE_ID') ? SITE_ID : '',
            'SITE_NAME' => $this->getSiteName(),
            'SERVER_NAME' => $this->getServerName(),
        ];
    }

    /**
     * Возвращает название текущего сайта.
     *
     * @return string
     */
    private function getSiteName(): string
    {
        if (!defined('SITE_ID')) {
            return '';
        }

        $site = CSite::GetByID(SITE_ID)->Fetch();

        return (string)($site['NAME'] ?? '');
    }

    /**
     * Возвращает домен текущего запроса.
     *
     * @return string
     */
    private function getServerName(): string
    {
        if (defined('SITE_SERVER_NAME') && SITE_SERVER_NAME !== '') {
            return SITE_SERVER_NAME;
        }

        return (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '');
    }

    /**
     * Отправляет JSON-ответ для AJAX-запроса.
     *
     * @return void
     */
    private function sendAjaxResponse(): void
    {
        global $APPLICATION;

        $APPLICATION->RestartBuffer();
        header('Content-Type: application/json; charset=UTF-8');

        echo Json::encode([
            'success' => (bool)($this->arResult['SUBMIT_RESULT']['SUCCESS'] ?? false),
            'resultId' => (int)($this->arResult['SUBMIT_RESULT']['RESULT_ID'] ?? 0),
            'errors' => $this->arResult['ERRORS'],
            'values' => $this->arResult['VALUES'],
        ]);

        CMain::FinalActions();
        die();
    }
}
