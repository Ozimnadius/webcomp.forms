<?php

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Loader;
use Bitrix\Main\EventManager;
use Webcomp\Forms\Form\IblockTypeInstaller;

Loc::loadMessages(__FILE__);

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
        $this->MODULE_NAME = Loc::getMessage('MODULE_NAME');
        $this->MODULE_DESCRIPTION = Loc::getMessage('MODULE_DESC');
        $this->PARTNER_NAME = Loc::getMessage('PARTNER_NAME');
        $this->PARTNER_URI = 'https://web-komp.ru';
    }

    public function DoInstall()
    {
        global $APPLICATION;
        $this->InstallFiles();
        $this->InstallEvents();
        ModuleManager::registerModule($this->MODULE_ID);
        Loader::includeModule($this->MODULE_ID);
        IblockTypeInstaller::install();
        $APPLICATION->IncludeAdminFile(
            Loc::getMessage('INSTALL_TITLE'),
            __DIR__ . '/step.php'
        );
    }

    public function DoUninstall()
    {
        global $APPLICATION;
        Loader::includeModule($this->MODULE_ID);
        $this->UnInstallEvents();
        IblockTypeInstaller::uninstall();
        $this->UnInstallFiles();
        ModuleManager::unRegisterModule($this->MODULE_ID);
        $APPLICATION->IncludeAdminFile(
            Loc::getMessage('UNINSTALL_TITLE'),
            __DIR__ . '/unstep.php'
        );
    }

    public function InstallFiles()
    {
        CopyDirFiles(__DIR__ . '/css', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/css/' . $this->MODULE_ID, true, true);
        CopyDirFiles(__DIR__ . '/js', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/js/' . $this->MODULE_ID, true, true);
    }

    public function UnInstallFiles()
    {
        DeleteDirFiles(__DIR__ . '/css', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/css/' . $this->MODULE_ID);
        DeleteDirFiles(__DIR__ . '/js', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/js/' . $this->MODULE_ID);
    }

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

    private function getIblockEventHandlers(): array
    {
        return [
            'OnAfterIBlockAdd' => 'onAfterIBlockAdd',
            'OnAfterIBlockUpdate' => 'onAfterIBlockUpdate',
            'OnAfterIBlockPropertyAdd' => 'onAfterIBlockPropertyAdd',
            'OnAfterIBlockPropertyUpdate' => 'onAfterIBlockPropertyUpdate',
            'OnAfterIBlockPropertyDelete' => 'onAfterIBlockPropertyDelete',
            'OnBeforeIBlockDelete' => 'onBeforeIBlockDelete',
        ];
    }
}
