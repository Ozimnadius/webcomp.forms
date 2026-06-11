<?php

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Loader;
use Bitrix\Main\EventManager;
use Webcomp\Forms\Form\IblockTypeInstaller;

Loc::loadMessages(__FILE__);

/**
 * Установщик модуля webcomp.forms.
 *
 * Устанавливает файлы (ассеты и компонент), регистрирует обработчики событий,
 * регистрирует модуль и создает тип инфоблоков forms. При удалении выполняет
 * обратные действия и подчищает данные модуля: инфоблоки форм, почтовые
 * сущности и опции.
 */
class webcomp_forms extends CModule
{
    public $MODULE_ID = 'webcomp.forms';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $PARTNER_NAME;
    public $PARTNER_URI;

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';
        $this->MODULE_VERSION = $arModuleVersion['VERSION'];
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'];
        $this->MODULE_NAME = Loc::getMessage('WEBCOMP_FORMS_MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('WEBCOMP_FORMS_MODULE_DESC');
        $this->PARTNER_NAME = Loc::getMessage('WEBCOMP_FORMS_PARTNER_NAME');
        $this->PARTNER_URI = 'https://web-komp.ru';
    }

    /**
     * Устанавливает модуль: файлы, события, регистрация, тип инфоблоков forms.
     *
     * @return void
     */
    public function DoInstall()
    {
        $this->InstallFiles();
        $this->InstallEvents();
        ModuleManager::registerModule($this->MODULE_ID);
        Loader::includeModule($this->MODULE_ID);
        IblockTypeInstaller::install();
    }

    /**
     * Удаляет модуль вместе с данными: инфоблоки форм, тип forms, почтовые
     * сущности, опции модуля, скопированные файлы и регистрации событий.
     *
     * @return void
     */
    public function DoUninstall()
    {
        Loader::includeModule($this->MODULE_ID);
        $this->UnInstallEvents();
        IblockTypeInstaller::uninstall();
        \Bitrix\Main\Config\Option::delete($this->MODULE_ID);
        $this->UnInstallFiles();
        ModuleManager::unRegisterModule($this->MODULE_ID);
    }

    /**
     * Копирует ассеты в /bitrix/css|js и компонент в каталог компонентов.
     *
     * @return void
     */
    public function InstallFiles()
    {
        CopyDirFiles(__DIR__ . '/css', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/css/' . $this->MODULE_ID, true, true);
        CopyDirFiles(__DIR__ . '/js', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/js/' . $this->MODULE_ID, true, true);
        CopyDirFiles(__DIR__ . '/components', $_SERVER['DOCUMENT_ROOT'] . $this->getComponentsPath(), true, true);
    }

    /**
     * Удаляет скопированные ассеты и компонент webcomp/form.
     *
     * @return void
     */
    public function UnInstallFiles()
    {
        DeleteDirFilesEx('/bitrix/css/' . $this->MODULE_ID);
        DeleteDirFilesEx('/bitrix/js/' . $this->MODULE_ID);
        DeleteDirFilesEx($this->getComponentsPath() . '/webcomp/form');
    }

    /**
     * Регистрирует обработчики: админский ассет, тип свойства formbuilder
     * и события инфоблоков для синхронизации почтовых сущностей.
     *
     * @return void
     */
    public function InstallEvents()
    {
        EventManager::getInstance()->registerEventHandler(
            'main',
            'OnBeforeProlog',
            $this->MODULE_ID,
            'Webcomp\\Forms\\EventHandlers\\AdminHandler',
            'onBeforeProlog'
        );

        EventManager::getInstance()->registerEventHandler(
            'iblock',
            'OnIBlockPropertyBuildList',
            $this->MODULE_ID,
            'Webcomp\\Forms\\PropertyTypes\\FormBuilder',
            'GetUserTypeDescription'
        );

        foreach ($this->getIblockEventHandlers() as $eventName => $methodName) {
            EventManager::getInstance()->registerEventHandler(
                'iblock',
                $eventName,
                $this->MODULE_ID,
                'Webcomp\\Forms\\EventHandlers\\IblockHandler',
                $methodName
            );
        }
    }

    /**
     * Снимает все регистрации обработчиков, созданные InstallEvents().
     *
     * @return void
     */
    public function UnInstallEvents()
    {
        EventManager::getInstance()->unRegisterEventHandler(
            'main',
            'OnBeforeProlog',
            $this->MODULE_ID,
            'Webcomp\\Forms\\EventHandlers\\AdminHandler',
            'onBeforeProlog'
        );

        EventManager::getInstance()->unRegisterEventHandler(
            'iblock',
            'OnIBlockPropertyBuildList',
            $this->MODULE_ID,
            'Webcomp\\Forms\\PropertyTypes\\FormBuilder',
            'GetUserTypeDescription'
        );

        foreach ($this->getIblockEventHandlers() as $eventName => $methodName) {
            EventManager::getInstance()->unRegisterEventHandler(
                'iblock',
                $eventName,
                $this->MODULE_ID,
                'Webcomp\\Forms\\EventHandlers\\IblockHandler',
                $methodName
            );
        }
    }

    /**
     * Возвращает карту "событие iblock => метод IblockHandler".
     *
     * Используется и при регистрации, и при снятии обработчиков, чтобы списки
     * не разъезжались.
     *
     * @return array<string, string>
     */
    private function getIblockEventHandlers(): array
    {
        return [
            'OnAfterIBlockAdd' => 'onAfterIBlockAdd',
            'OnAfterIBlockUpdate' => 'onAfterIBlockUpdate',
            'OnAfterIBlockPropertyAdd' => 'onAfterIBlockPropertyAdd',
            'OnAfterIBlockPropertyUpdate' => 'onAfterIBlockPropertyUpdate',
            'OnAfterIBlockPropertyDelete' => 'onAfterIBlockPropertyDelete',
            'OnIBlockDelete' => 'onIBlockDelete',
        ];
    }

    /**
     * Возвращает путь каталога компонентов относительно корня сайта.
     *
     * @return string Путь вида /local/components или /bitrix/components.
     */
    private function getComponentsPath(): string
    {
        return '/' . $this->getInstallRoot() . '/components';
    }

    /**
     * Определяет корень установки модуля по его фактическому расположению.
     *
     * @return string local или bitrix.
     */
    private function getInstallRoot(): string
    {
        $documentRoot = rtrim(str_replace('\\', '/', (string)$_SERVER['DOCUMENT_ROOT']), '/');
        $installDir = str_replace('\\', '/', __DIR__);

        return str_starts_with($installDir, $documentRoot . '/local/modules/')
            ? 'local'
            : 'bitrix';
    }
}
