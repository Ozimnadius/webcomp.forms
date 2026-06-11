<?php

namespace Webcomp\Forms\Form;


use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Mail\Internal\EventTypeTable;
use Bitrix\Main\Mail\Internal\EventMessageTable;
use Bitrix\Main\Mail\Internal\EventMessageSiteTable;
use Bitrix\Main\Mail\Internal\EventMessageAttachmentTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Json;
use Webcomp\Forms\PropertyTypes\FormBuilder;

use Throwable;

/**
 * Синхронизирует почтовые сущности Bitrix для инфоблоков форм.
 *
 * Один инфоблок типа forms соответствует одной публичной форме. На основе
 * инфоблока и его активных свойств класс создает или обновляет тип почтового
 * события, один раз создает почтовый шаблон и поддерживает привязку шаблона
 * к сайтам инфоблока. Чтение форм и их привязок к сайтам выполняется через
 * FormRepository. Связь формы с событием и шаблоном хранится в опциях модуля.
 *
 * Публичные методы безопасны для вызова из обработчиков событий Bitrix: все
 * исключения внутренних D7 ORM-операций перехватываются и пишутся в журнал.
 */
class MailEventSynchronizer
{
    private const EVENT_PREFIX = 'WEBCOMP_FORM_';
    private const MODULE_ID = 'webcomp.forms';
    private const OPTION_PREFIX = 'mail_binding_';

    /**
     * Безопасно синхронизирует тип почтового события и почтовый шаблон формы.
     *
     * Метод можно вызывать из обработчиков добавления/изменения инфоблока и его
     * свойств. Если инфоблок не относится к типу forms, синхронизация не выполняется.
     *
     * @param int $iblockId ID инфоблока формы.
     *
     * @return void
     */
    public static function syncByIblockId(int $iblockId): void
    {
        try {
            self::doSyncByIblockId($iblockId);
        } catch (Throwable $exception) {
            self::logException($exception);
        }
    }

    /**
     * Выполняет синхронизацию почтовых сущностей формы.
     *
     * Метод обновляет описание типа почтового события, при первом обращении
     * создает почтовый шаблон с автогенерированным телом, а для существующего
     * шаблона синхронизирует только привязку к сайтам, не трогая его содержимое.
     *
     * @param int $iblockId ID инфоблока формы.
     *
     * @return void
     *
     * @throws \Exception При ошибках D7 ORM-операций.
     */
    private static function doSyncByIblockId(int $iblockId): void
    {
        $iblock = (new FormRepository())->getFormById($iblockId);

        if ($iblock === null) {
            return;
        }

        $properties = [];
        $propertyRows = PropertyTable::getList([
            'select' => [
                'ID',
                'IBLOCK_ID',
                'NAME',
                'CODE',
                'SORT',
                'ACTIVE',
                'PROPERTY_TYPE',
                'USER_TYPE',
                'USER_TYPE_SETTINGS',
            ],
            'filter' => [
                '=IBLOCK_ID' => $iblockId,
                '=ACTIVE' => 'Y',
                '=PROPERTY_TYPE' => FormBuilder::PROPERTY_TYPE,
                '=USER_TYPE' => FormBuilder::USER_TYPE,
            ],
            'order' => [
                'SORT' => 'ASC',
                'ID' => 'ASC',
            ],
        ]);

        while ($property = $propertyRows->fetch()) {
            $properties[] = $property;
        }

        $siteIds = $iblock['SITE_IDS'];
        $eventName = self::getEventName($iblock);
        $binding = self::getBinding($iblockId);

        if ($binding !== null && $binding['EVENT_NAME'] !== '' && $binding['EVENT_NAME'] !== $eventName) {
            self::renameEvent($binding['EVENT_NAME'], $eventName);
        }

        $eventFields = [
            'LID' => LANGUAGE_ID,
            'EVENT_NAME' => $eventName,
            'NAME' => 'Форма: ' . $iblock['NAME'],
            'DESCRIPTION' => self::buildEventDescription($iblock, $properties),
        ];

        $eventType = EventTypeTable::getList([
            'select' => ['ID'],
            'filter' => [
                '=LID' => LANGUAGE_ID,
                '=EVENT_NAME' => $eventName,
            ],
            'limit' => 1,
        ])->fetch();

        if ($eventType) {
            EventTypeTable::update((int)$eventType['ID'], $eventFields);
        } else {
            $eventFields['EVENT_TYPE'] = EventTypeTable::TYPE_EMAIL;
            EventTypeTable::add($eventFields);
        }

        $messageId = (int)($binding['MESSAGE_ID'] ?? 0);
        $message = null;

        if ($messageId > 0) {
            $message = EventMessageTable::getList([
                'select' => ['ID'],
                'filter' => [
                    '=ID' => $messageId,
                    '=EVENT_NAME' => $eventName,
                ],
                'limit' => 1,
            ])->fetch();
        }

        if (!$message) {
            $message = EventMessageTable::getList([
                'select' => ['ID'],
                'filter' => [
                    '=EVENT_NAME' => $eventName,
                ],
                'limit' => 1,
            ])->fetch();
        }

        if ($message) {
            // Тело существующего шаблона не перезаписывается, чтобы не терять
            // ручные правки администратора. Синхронизируется только привязка к сайтам.
            $messageId = (int)$message['ID'];
            self::syncMessageSites($messageId, $siteIds);
        } else {
            $messageResult = EventMessageTable::add([
                'ACTIVE' => 'Y',
                'EVENT_NAME' => $eventName,
                'EMAIL_FROM' => '#DEFAULT_EMAIL_FROM#',
                'EMAIL_TO' => '#DEFAULT_EMAIL_FROM#',
                'SUBJECT' => 'Заполнена форма "#FORM_NAME#"',
                'MESSAGE' => self::buildMessageBody($iblock, $properties),
                'BODY_TYPE' => 'html',
                'LANGUAGE_ID' => LANGUAGE_ID,
            ]);

            $messageId = $messageResult->isSuccess() ? (int)$messageResult->getId() : 0;

            if ($messageId > 0) {
                self::syncMessageSites($messageId, $siteIds);
            }
        }

        if ($messageId > 0) {
            self::saveBinding($iblockId, $eventName, $messageId);
        }
    }

    /**
     * Безопасно удаляет почтовые сущности, связанные с инфоблоком формы.
     *
     * Метод нужно вызывать при удалении инфоблока, пока его строка еще
     * существует в базе. Если инфоблок не относится к типу forms, удаление
     * почтовых сущностей не выполняется.
     *
     * @param int $iblockId ID инфоблока формы.
     *
     * @return void
     */
    public static function deleteByIblock(int $iblockId): void
    {
        try {
            self::doDeleteByIblock($iblockId);
        } catch (Throwable $exception) {
            self::logException($exception);
        }
    }

    /**
     * Выполняет удаление почтовых шаблонов, их привязок к сайтам, вложений,
     * типа события и сохраненной привязки формы. Имя события берется из
     * привязки в опциях модуля (надежно при переименованном коде), с fallback
     * на вычисление из текущего кода инфоблока.
     *
     * @param int $iblockId ID инфоблока формы.
     *
     * @return void
     *
     * @throws \Exception При ошибках D7 ORM-операций.
     */
    private static function doDeleteByIblock(int $iblockId): void
    {
        $iblock = (new FormRepository())->getFormById($iblockId);

        if ($iblock === null) {
            return;
        }

        $binding = self::getBinding($iblockId);
        $eventName = $binding !== null && $binding['EVENT_NAME'] !== ''
            ? $binding['EVENT_NAME']
            : self::getEventName($iblock);

        $messages = EventMessageTable::getList([
            'select' => ['ID'],
            'filter' => [
                '=EVENT_NAME' => $eventName,
            ],
        ]);

        while ($message = $messages->fetch()) {
            $messageId = (int)$message['ID'];

            $attachments = EventMessageAttachmentTable::getList([
                'select' => ['EVENT_MESSAGE_ID', 'FILE_ID'],
                'filter' => [
                    '=EVENT_MESSAGE_ID' => $messageId,
                ],
            ]);

            while ($attachment = $attachments->fetch()) {
                EventMessageAttachmentTable::delete([
                    'EVENT_MESSAGE_ID' => (int)$attachment['EVENT_MESSAGE_ID'],
                    'FILE_ID' => (int)$attachment['FILE_ID'],
                ]);
            }

            EventMessageSiteTable::deleteByFilter([
                '=EVENT_MESSAGE_ID' => $messageId,
            ]);

            EventMessageTable::delete($messageId);
        }

        $eventTypes = EventTypeTable::getList([
            'select' => ['ID'],
            'filter' => [
                '=EVENT_NAME' => $eventName,
            ],
        ]);

        while ($eventType = $eventTypes->fetch()) {
            EventTypeTable::delete((int)$eventType['ID']);
        }

        self::deleteBinding($iblockId);
    }

    /**
     * Формирует системное имя почтового события для формы.
     *
     * Если у инфоблока заполнен символьный код, используется он. Иначе в имя
     * события подставляется ID инфоблока. Итоговое имя приводится к верхнему
     * регистру и содержит только латинские буквы, цифры и подчеркивания.
     *
     * @param array<string, mixed> $iblock Данные инфоблока.
     *
     * @return string Имя события вида WEBCOMP_FORM_<CODE>.
     */
    public static function getEventName(array $iblock): string
    {
        $code = trim((string)($iblock['CODE'] ?? ''));

        if ($code === '') {
            $code = (string)($iblock['ID'] ?? '');
        }

        $code = mb_strtoupper($code);
        $code = preg_replace('/[^A-Z0-9_]+/', '_', $code);
        $code = trim((string)$code, '_');

        if ($code === '') {
            $code = (string)(int)($iblock['ID'] ?? 0);
        }

        return self::EVENT_PREFIX . $code;
    }

    /**
     * Формирует описание макросов для типа почтового события.
     *
     * В описание добавляются служебные макросы формы и один макрос на каждое
     * активное свойство инфоблока с заполненным символьным кодом.
     *
     * @param array<string, mixed> $iblock Данные инфоблока формы.
     * @param array<int, array<string, mixed>> $properties Список свойств формы.
     *
     * @return string Описание макросов для карточки типа события.
     */
    public static function buildEventDescription(array $iblock, array $properties): string
    {
        $lines = [
            'Форма: ' . ($iblock['NAME'] ?? ''),
            'ID формы: ' . (int)($iblock['ID'] ?? 0),
            '',
            '#FORM_NAME# - название формы',
            '#FORM_CODE# - код формы',
            '#FORM_ID# - ID инфоблока формы',
            '#RESULT_ID# - ID результата',
            '#DATE_CREATE# - дата отправки',
            '#SITE_NAME# - название сайта',
            '#SERVER_NAME# - домен сайта',
            '#ADMIN_RESULT_URL# - ссылка на результат в админке',
        ];

        foreach ($properties as $property) {
            $code = trim((string)($property['CODE'] ?? ''));

            if ($code === '') {
                continue;
            }

            $name = trim((string)($property['NAME'] ?? $code));
            $lines[] = '#' . $code . '# - ' . $name;
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Формирует HTML-тело почтового шаблона формы.
     *
     * Тело содержит служебную информацию о форме и строки с макросами активных
     * свойств, у которых заполнен символьный код.
     *
     * @param array<string, mixed> $iblock Данные инфоблока формы.
     * @param array<int, array<string, mixed>> $properties Список свойств формы.
     *
     * @return string HTML-тело почтового шаблона.
     */
    public static function buildMessageBody(array $iblock, array $properties): string
    {
        $lines = [
            'Заполнена форма "#FORM_NAME#" на сайте #SITE_NAME#<br>',
            '<br>',
            'Форма: ' . htmlspecialcharsbx((string)($iblock['NAME'] ?? '')) . '<br>',
            'ID формы: ' . (int)($iblock['ID'] ?? 0) . '<br>',
            '<br>',
        ];

        foreach ($properties as $property) {
            $code = trim((string)($property['CODE'] ?? ''));

            if ($code === '') {
                continue;
            }

            $name = trim((string)($property['NAME'] ?? $code));
            $lines[] = htmlspecialcharsbx($name) . ': #' . $code . '#<br>';
        }

        $lines[] = '<br>';
        $lines[] = 'Дата отправки: #DATE_CREATE#<br>';
        $lines[] = 'Результат в админке: #ADMIN_RESULT_URL#';

        return implode(PHP_EOL, $lines);
    }

    /**
     * Записывает ошибку синхронизации в журнал Bitrix.
     *
     * @param Throwable $exception Перехваченное исключение или ошибка.
     *
     * @return void
     */
    private static function logException(Throwable $exception): void
    {
        if (!function_exists('AddMessage2Log')) {
            return;
        }

        AddMessage2Log(
            sprintf(
                '[webcomp.forms] Mail event synchronization error: %s in %s:%d',
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine()
            ),
            'webcomp.forms'
        );
    }

    /**
     * Пересобирает привязку почтового шаблона к сайтам.
     *
     * Старые связи шаблона с сайтами удаляются, затем создаются новые связи из
     * актуального списка сайтов инфоблока.
     *
     * @param int $messageId ID почтового шаблона.
     * @param array<int, string> $siteIds Список ID сайтов.
     *
     * @return void
     *
     * @throws \Exception При ошибках D7 ORM-операций.
     */
    private static function syncMessageSites(int $messageId, array $siteIds): void
    {
        if ($messageId <= 0) {
            return;
        }

        EventMessageSiteTable::deleteByFilter([
            '=EVENT_MESSAGE_ID' => $messageId,
        ]);

        foreach ($siteIds as $siteId) {
            $siteId = trim((string)$siteId);

            if ($siteId === '') {
                continue;
            }

            EventMessageSiteTable::add([
                'EVENT_MESSAGE_ID' => $messageId,
                'SITE_ID' => $siteId,
            ]);
        }
    }

    /**
     * Возвращает сохраненную привязку формы к почтовым сущностям.
     *
     * @param int $iblockId ID инфоблока формы.
     *
     * @return array{EVENT_NAME: string, MESSAGE_ID: int}|null
     */
    private static function getBinding(int $iblockId): ?array
    {
        $raw = (string)Option::get(self::MODULE_ID, self::OPTION_PREFIX . $iblockId, '');

        if ($raw === '') {
            return null;
        }

        try {
            $binding = Json::decode($raw);
        } catch (Throwable $exception) {
            return null;
        }

        if (!is_array($binding)) {
            return null;
        }

        return [
            'EVENT_NAME' => trim((string)($binding['EVENT_NAME'] ?? '')),
            'MESSAGE_ID' => (int)($binding['MESSAGE_ID'] ?? 0),
        ];
    }

    /**
     * Сохраняет привязку формы к типу события и почтовому шаблону.
     *
     * @param int $iblockId ID инфоблока формы.
     * @param string $eventName Имя почтового события.
     * @param int $messageId ID почтового шаблона.
     *
     * @return void
     */
    private static function saveBinding(int $iblockId, string $eventName, int $messageId): void
    {
        Option::set(self::MODULE_ID, self::OPTION_PREFIX . $iblockId, Json::encode([
            'EVENT_NAME' => $eventName,
            'MESSAGE_ID' => $messageId,
        ]));
    }

    /**
     * Удаляет сохраненную привязку формы.
     *
     * @param int $iblockId ID инфоблока формы.
     *
     * @return void
     */
    private static function deleteBinding(int $iblockId): void
    {
        Option::delete(self::MODULE_ID, ['name' => self::OPTION_PREFIX . $iblockId]);
    }

    /**
     * Переносит тип события и все его шаблоны на новое имя события.
     *
     * Используется при смене символьного кода инфоблока: вместо создания новых
     * сущностей (и сирот со старым именем) существующие переименовываются,
     * что сохраняет шаблон и его ручные правки.
     *
     * @param string $oldName Старое имя события.
     * @param string $newName Новое имя события.
     *
     * @return void
     *
     * @throws \Exception При ошибках D7 ORM-операций.
     */
    private static function renameEvent(string $oldName, string $newName): void
    {
        if ($oldName === '' || $oldName === $newName) {
            return;
        }

        $eventTypes = EventTypeTable::getList([
            'select' => ['ID'],
            'filter' => ['=EVENT_NAME' => $oldName],
        ]);

        while ($eventType = $eventTypes->fetch()) {
            EventTypeTable::update((int)$eventType['ID'], ['EVENT_NAME' => $newName]);
        }

        $messages = EventMessageTable::getList([
            'select' => ['ID'],
            'filter' => ['=EVENT_NAME' => $oldName],
        ]);

        while ($message = $messages->fetch()) {
            EventMessageTable::update((int)$message['ID'], ['EVENT_NAME' => $newName]);
        }
    }
}
