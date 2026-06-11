<?php

namespace Webcomp\Forms\Form;

use Bitrix\Main\Loader;
use RuntimeException;

/**
 * Управляет типом инфоблоков, в котором хранятся формы модуля.
 *
 * Класс используется установщиком модуля: при установке создает тип инфоблоков
 * forms, а при удалении очищает инфоблоки этого типа и сам тип инфоблоков.
 */
class IblockTypeInstaller
{
    /**
     * Символьный код типа инфоблоков форм.
     */
    public const TYPE_ID = 'forms';

    /**
     * Создает тип инфоблоков forms, если он еще не существует.
     *
     * @return void
     *
     * @throws RuntimeException Если ядро отказало в создании типа инфоблоков.
     */
    public static function install(): void
    {
        if (!Loader::includeModule('iblock')) {
            return;
        }

        if (\CIBlockType::GetByID(self::TYPE_ID)->Fetch()) {
            return;
        }

        $iblockType = new \CIBlockType();
        $result = $iblockType->Add([
            'ID' => self::TYPE_ID,
            'SECTIONS' => 'N',
            'IN_RSS' => 'N',
            'SORT' => 500,
            'LANG' => [
                'ru' => [
                    'NAME' => 'Формы',
                    'SECTION_NAME' => 'Разделы',
                    'ELEMENT_NAME' => 'Результаты',
                ],
                'en' => [
                    'NAME' => 'Forms',
                    'SECTION_NAME' => 'Sections',
                    'ELEMENT_NAME' => 'Results',
                ],
            ],
        ]);

        if (!$result) {
            throw new RuntimeException(
                'Не удалось создать тип инфоблоков forms: ' . (string)$iblockType->LAST_ERROR
            );
        }
    }

    /**
     * Удаляет все инфоблоки форм и сам тип инфоблоков forms.
     *
     * Перед удалением каждого инфоблока дополнительно удаляются связанные
     * почтовые события и почтовые шаблоны.
     *
     * @return void
     */
    public static function uninstall(): void
    {
        if (!Loader::includeModule('iblock')) {
            return;
        }

        $iblocks = \CIBlock::GetList([], [
            'TYPE' => self::TYPE_ID,
        ]);

        while ($iblock = $iblocks->Fetch()) {
            $iblockId = (int)$iblock['ID'];
            MailEventSynchronizer::deleteByIblock($iblockId);
            \CIBlock::Delete($iblockId);
        }

        if (\CIBlockType::GetByID(self::TYPE_ID)->Fetch()) {
            \CIBlockType::Delete(self::TYPE_ID);
        }
    }
}
