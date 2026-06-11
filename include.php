<?php

use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses(
    'webcomp.forms',
    [
        'Webcomp\\Forms\\Form\\IblockTypeInstaller' => 'lib/Form/IblockTypeInstaller.php',
        'Webcomp\\Forms\\Form\\MailEventSynchronizer' => 'lib/Form/MailEventSynchronizer.php',
        'Webcomp\\Forms\\Form\\FormRepository' => 'lib/Form/FormRepository.php',
        'Webcomp\\Forms\\Form\\ResultService' => 'lib/Form/ResultService.php',
        'Webcomp\\Forms\\EventHandlers\\IblockHandler' => 'lib/EventHandlers/IblockHandler.php',
        'Webcomp\\Forms\\EventHandlers\\AdminHandler' => 'lib/EventHandlers/AdminHandler.php',
        'Webcomp\\Forms\\PropertyTypes\\FormBuilder' => 'lib/PropertyTypes/FormBuilder.php',
    ]
);