<?php

namespace Webcomp\Forms\Form;


use Bitrix\Main\Loader;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\IblockSiteTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Mail\Internal\EventTypeTable;
use Bitrix\Main\Mail\Internal\EventMessageTable;
use Bitrix\Main\Mail\Internal\EventMessageSiteTable;
use Bitrix\Main\Mail\Internal\EventMessageAttachmentTable;
use Webcomp\Forms\PropertyTypes\FormBuilder;
use Throwable;

/**
 * Синхронизирует почтовые сущности Bitrix для инфоблоков форм.
 *
 * Один инфоблок типа forms соответствует одной публичной форме. На основе
 * инфоблока и его активных свойств класс создает или обновляет тип почтового
 * события, почтовый шаблон, язык шаблона и привязку шаблона к сайтам инфоблока.
 *
 * Публичные методы безопасны для вызова из обработчиков событий Bitrix: все
 * исключения внутренних D7 ORM-операций перехватываются и пишутся в журнал.
 */
class MailEventSynchronizer
{
    private const EVENT_PREFIX = 'WEBCOMP_FORM_';

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
     * Метод обновляет описание типа почтового события, создает или обновляет
     * почтовый шаблон, пересобирает тело письма по активным свойствам формы,
     * выставляет язык шаблона и синхронизирует привязку шаблона к сайтам.
     *
     * @param int $iblockId ID инфоблока формы.
     *
     * @return void
     *
     * @throws \Exception При ошибках D7 ORM-операций.
     */
    private static function doSyncByIblockId(int $iblockId): void
    {
        $iblock = self::getFormsIblock($iblockId);

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

        $siteIds = self::getIblockSiteIds($iblockId);
        $eventName = self::getEventName($iblock);
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

        $message = EventMessageTable::getList([
            'select' => ['ID'],
            'filter' => [
                '=EVENT_NAME' => $eventName,
            ],
            'limit' => 1,
        ])->fetch();

        if ($message) {
            $messageId = (int)$message['ID'];

            EventMessageTable::update($messageId, [
                'LANGUAGE_ID' => LANGUAGE_ID,
                'MESSAGE' => self::buildMessageBody($iblock, $properties),
                'BODY_TYPE' => 'html',
            ]);

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

            if ($messageResult->isSuccess()) {
                self::syncMessageSites((int)$messageResult->getId(), $siteIds);
            }
        }
    }

    /**
     * Безопасно удаляет почтовые сущности, связанные с инфоблоком формы.
     *
     * Метод можно вызывать перед удалением инфоблока. Если инфоблок не относится
     * к типу forms, удаление почтовых сущностей не выполняется.
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
     * Выполняет удаление почтовых шаблонов, их привязок, вложений и типа события.
     *
     * @param int $iblockId ID инфоблока формы.
     *
     * @return void
     *
     * @throws \Exception При ошибках D7 ORM-операций.
     */
    private static function doDeleteByIblock(int $iblockId): void
    {
        $iblock = self::getFormsIblock($iblockId);

        if ($iblock === null) {
            return;
        }

        $eventName = self::getEventName($iblock);

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
            $code = 'UNKNOWN';
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
     * Возвращает список сайтов, к которым привязан инфоблок формы.
     *
     * @param int $iblockId ID инфоблока формы.
     *
     * @return array<int, string> Список ID сайтов.
     *
     * @throws \Exception При ошибках D7 ORM-операций.
     */
    private static function getIblockSiteIds(int $iblockId): array
    {
        $siteIds = [];
        $sites = IblockSiteTable::getList([
            'select' => ['SITE_ID'],
            'filter' => ['=IBLOCK_ID' => $iblockId],
            'order' => ['SITE_ID' => 'ASC'],
        ]);

        while ($site = $sites->fetch()) {
            $siteId = trim((string)($site['SITE_ID'] ?? ''));

            if ($siteId !== '') {
                $siteIds[] = $siteId;
            }
        }

        return array_values(array_unique($siteIds));
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
     * Возвращает инфоблок формы или null, если инфоблок не подходит.
     *
     * Метод подключает необходимые модули, получает инфоблок по ID и проверяет,
     * что он относится к типу forms.
     *
     * @param int $iblockId ID инфоблока.
     *
     * @return array<string, mixed>|null Данные инфоблока формы или null.
     *
     * @throws \Exception При ошибках подключения модулей или D7 ORM-операций.
     */
    private static function getFormsIblock(int $iblockId): ?array
    {
        if ($iblockId <= 0) {
            return null;
        }

        if (!Loader::includeModule('main') || !Loader::includeModule('iblock')) {
            return null;
        }

        $iblock = IblockTable::getList([
            'select' => ['ID', 'IBLOCK_TYPE_ID', 'CODE', 'NAME'],
            'filter' => ['=ID' => $iblockId],
            'limit' => 1,
        ])->fetch();

        if (!$iblock || ($iblock['IBLOCK_TYPE_ID'] ?? '') !== IblockTypeInstaller::TYPE_ID) {
            return null;
        }

        return $iblock;
    }
}
