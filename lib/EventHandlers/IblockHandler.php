<?php

namespace Webcomp\Forms\EventHandlers;

use Webcomp\Forms\Form\MailEventSynchronizer;

/**
 * Обрабатывает события инфоблоков и свойств для синхронизации почтовых сущностей форм.
 *
 * При создании или изменении инфоблока/свойств запускает обновление почтового
 * события и шаблона. При удалении инфоблока удаляет связанные почтовые
 * сущности и блокирует повторную синхронизацию на событиях удаления свойств.
 */
class IblockHandler
{
    /**
     * ID инфоблоков, которые сейчас удаляются.
     *
     * Нужен, чтобы события удаления свойств внутри CIBlock::Delete() не создали
     * почтовый шаблон заново после удаления.
     *
     * @var array<int, bool>
     */
    private static array $deletingIblocks = [];

    /**
     * Обработчик события добавления инфоблока.
     *
     * @param array $fields Поля добавленного инфоблока.
     *
     * @return void
     */
    public static function onAfterIBlockAdd(array &$fields): void
    {
        self::syncIblockFromFields($fields);
    }

    /**
     * Обработчик события изменения инфоблока.
     *
     * @param array $fields Поля измененного инфоблока.
     *
     * @return void
     */
    public static function onAfterIBlockUpdate(array &$fields): void
    {
        self::syncIblockFromFields($fields);
    }

    /**
     * Обработчик события добавления свойства инфоблока.
     *
     * @param array $fields Поля добавленного свойства.
     *
     * @return void
     */
    public static function onAfterIBlockPropertyAdd(array &$fields): void
    {
        self::syncIblockFromPropertyFields($fields);
    }

    /**
     * Обработчик события изменения свойства инфоблока.
     *
     * @param array $fields Поля измененного свойства.
     *
     * @return void
     */
    public static function onAfterIBlockPropertyUpdate(array &$fields): void
    {
        self::syncIblockFromPropertyFields($fields);
    }

    /**
     * Обработчик события удаления свойства инфоблока.
     *
     * @param array $property Данные удаляемого свойства.
     *
     * @return void
     */
    public static function onAfterIBlockPropertyDelete(array $property): void
    {
        self::syncIblockFromPropertyFields($property);
    }

    /**
     * Обработчик события удаления инфоблока.
     *
     * Событие OnIBlockDelete вызывается ядром после того, как все обработчики
     * OnBeforeIBlockDelete разрешили удаление: отменить его на этом этапе уже
     * нельзя, а строка инфоблока еще существует и доступна для чтения.
     *
     * @param int $iblockId ID удаляемого инфоблока.
     *
     * @return void
     */
    public static function onIBlockDelete(int $iblockId): void
    {
        self::$deletingIblocks[$iblockId] = true;

        MailEventSynchronizer::deleteByIblock($iblockId);
    }

    /**
     * Запускает синхронизацию по данным события инфоблока.
     *
     * @param array $fields Поля инфоблока из события Bitrix.
     *
     * @return void
     */
    private static function syncIblockFromFields(array $fields): void
    {
        $iblockId = (int)($fields['ID'] ?? 0);

        if ($iblockId > 0) {
            MailEventSynchronizer::syncByIblockId($iblockId);
        }
    }

    /**
     * Запускает синхронизацию по данным события свойства инфоблока.
     *
     * @param array $fields Поля свойства из события Bitrix.
     *
     * @return void
     */
    private static function syncIblockFromPropertyFields(array $fields): void
    {
        $iblockId = (int)($fields['IBLOCK_ID'] ?? 0);

        if (isset(self::$deletingIblocks[$iblockId])) {
            return;
        }

        if ($iblockId > 0) {
            MailEventSynchronizer::syncByIblockId($iblockId);
        }
    }
}
