<?php

namespace Webcomp\Forms\EventHandlers;

use Bitrix\Main\Application;
use Bitrix\Main\Page\Asset;
use Webcomp\Forms\PropertyTypes\FormBuilder;

/**
 * Подключает административные ассеты модуля на нужных страницах Bitrix.
 *
 * Сейчас обработчик используется для страницы редактирования инфоблока forms:
 * он передает в JS допустимый тип свойства и подключает скрипт, который
 * ограничивает выбор типа свойства вариантом "Поле формы".
 */
class AdminHandler
{
    /**
     * Тип инфоблоков, для которого нужен административный JS модуля.
     */
    private const FORMS_IBLOCK_TYPE = 'forms';

    /**
     * Административная страница редактирования инфоблока.
     */
    private const IBLOCK_EDIT_PAGE = '/bitrix/admin/iblock_edit.php';

    /**
     * Путь к установленному JS-файлу ограничения типов свойств.
     */
    private const ADMIN_IBLOCK_EDIT_JS = '/bitrix/js/webcomp.forms/admin-iblock-edit.js';

    /**
     * Подключает JS ограничения типов свойств на странице редактирования forms-инфоблока.
     *
     * @return void
     */
    public static function onBeforeProlog(): void
    {
        if (!defined('ADMIN_SECTION') || ADMIN_SECTION !== true) {
            return;
        }

        $request = Application::getInstance()->getContext()->getRequest();

        if ($request->getRequestedPage() !== self::IBLOCK_EDIT_PAGE) {
            return;
        }

        if ((string)$request->get('type') !== self::FORMS_IBLOCK_TYPE) {
            return;
        }

        $asset = Asset::getInstance();
        $asset->addString(
            '<script>window.WebcompFormsAdminIblockEdit = window.WebcompFormsAdminIblockEdit || {};'
            . 'window.WebcompFormsAdminIblockEdit.allowedType = "' . \CUtil::JSEscape(FormBuilder::USER_TYPE_SELECT_VALUE) . '";</script>',
            true
        );
        $asset->addString(
            '<script src="' . htmlspecialcharsbx(\CUtil::GetAdditionalFileURL(self::ADMIN_IBLOCK_EDIT_JS, true)) . '"></script>',
            true
        );
    }
}
