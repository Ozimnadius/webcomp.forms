<?php

namespace Webcomp\Forms\Form;

use Bitrix\Iblock\IblockSiteTable;
use Bitrix\Iblock\IblockTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Loader;
use Webcomp\Forms\PropertyTypes\FormBuilder;

/**
 * Читает описание форм из инфоблоков Bitrix.
 *
 * Репозиторий скрывает детали хранения формы в инфоблоках: проверяет тип
 * инфоблока, получает привязки к сайтам и превращает свойства пользовательского
 * типа formbuilder в нормализованный массив полей для компонента и сервисов.
 */
class FormRepository
{
    /**
     * Возвращает форму по ID инфоблока.
     *
     * @param int $iblockId ID инфоблока формы.
     *
     * @return array|null Массив формы или null, если инфоблок не найден либо не относится к типу forms.
     *
     * @throws \Exception При ошибке D7 ORM.
     */
    public function getFormById(int $iblockId): ?array
    {
        if ($iblockId <= 0 || !$this->includeIblockModule()) {
            return null;
        }

        $iblock = IblockTable::getList([
            'select' => ['ID', 'IBLOCK_TYPE_ID', 'CODE', 'NAME'],
            'filter' => [
                '=ID' => $iblockId,
                '=IBLOCK_TYPE_ID' => IblockTypeInstaller::TYPE_ID,
            ],
            'limit' => 1,
        ])->fetch();

        return is_array($iblock) ? $this->normalizeForm($iblock) : null;
    }

    /**
     * Возвращает форму по символьному коду инфоблока.
     *
     * @param string $code Символьный код инфоблока формы.
     *
     * @return array|null Массив формы или null, если форма не найдена.
     *
     * @throws \Exception При ошибке D7 ORM.
     */
    public function getFormByCode(string $code): ?array
    {
        $code = trim($code);

        if ($code === '' || !$this->includeIblockModule()) {
            return null;
        }

        $iblock = IblockTable::getList([
            'select' => ['ID', 'IBLOCK_TYPE_ID', 'CODE', 'NAME'],
            'filter' => [
                '=IBLOCK_TYPE_ID' => IblockTypeInstaller::TYPE_ID,
                '=CODE' => $code,
            ],
            'order' => ['ID' => 'ASC'],
            'limit' => 1,
        ])->fetch();

        return is_array($iblock) ? $this->normalizeForm($iblock) : null;
    }

    /**
     * Возвращает нормализованные поля формы.
     *
     * В выборку попадают только активные свойства пользовательского типа
     * FormBuilder. Обычные свойства инфоблока игнорируются.
     *
     * @param int $iblockId ID инфоблока формы.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws \Exception При ошибке D7 ORM.
     */
    public function getFields(int $iblockId): array
    {
        if ($iblockId <= 0 || !$this->includeIblockModule()) {
            return [];
        }

        $fields = [];
        $propertyRows = PropertyTable::getList([
            'select' => [
                'ID',
                'IBLOCK_ID',
                'NAME',
                'CODE',
                'SORT',
                'ACTIVE',
                'MULTIPLE',
                'IS_REQUIRED',
                'PROPERTY_TYPE',
                'USER_TYPE',
                'USER_TYPE_SETTINGS',
            ],
            'filter' => [
                '=IBLOCK_ID' => $iblockId,
                '=ACTIVE' => 'Y',
                '=PROPERTY_TYPE' => FormBuilder::PROPERTY_TYPE,
                '=USER_TYPE' => FormBuilder::USER_TYPE,
                '!CODE' => false,
            ],
            'order' => [
                'SORT' => 'ASC',
                'ID' => 'ASC',
            ],
        ]);

        while ($property = $propertyRows->fetch()) {
            $field = $this->normalizeField($property);

            if ($field !== null) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Подключает модуль инфоблоков перед обращением к ORM.
     *
     * @return bool
     */
    private function includeIblockModule(): bool
    {
        return Loader::includeModule('iblock');
    }

    /**
     * Приводит строку инфоблока к формату формы модуля.
     *
     * @param array $iblock Строка из IblockTable.
     *
     * @return array<string, mixed>
     *
     * @throws \Exception При ошибке D7 ORM.
     */
    private function normalizeForm(array $iblock): array
    {
        $iblockId = (int)$iblock['ID'];

        return [
            'ID' => $iblockId,
            'IBLOCK_TYPE_ID' => (string)$iblock['IBLOCK_TYPE_ID'],
            'CODE' => (string)$iblock['CODE'],
            'NAME' => (string)$iblock['NAME'],
            'SITE_IDS' => $this->getSiteIds($iblockId),
        ];
    }

    /**
     * Возвращает список сайтов, к которым привязан инфоблок формы.
     *
     * @param int $iblockId ID инфоблока формы.
     *
     * @return array<int, string>
     *
     * @throws \Exception При ошибке D7 ORM.
     */
    private function getSiteIds(int $iblockId): array
    {
        $siteIds = [];
        $siteRows = IblockSiteTable::getList([
            'select' => ['SITE_ID'],
            'filter' => ['=IBLOCK_ID' => $iblockId],
            'order' => ['SITE_ID' => 'ASC'],
        ]);

        while ($site = $siteRows->fetch()) {
            $siteId = trim((string)($site['SITE_ID'] ?? ''));

            if ($siteId !== '') {
                $siteIds[] = $siteId;
            }
        }

        return $siteIds;
    }

    /**
     * Приводит свойство инфоблока к формату поля публичной формы.
     *
     * @param array $property Строка свойства из PropertyTable.
     *
     * @return array<string, mixed>|null
     */
    private function normalizeField(array $property): ?array
    {
        $code = trim((string)($property['CODE'] ?? ''));

        if ($code === '') {
            return null;
        }

        $settings = FormBuilder::PrepareSettings($property);
        $propertyName = (string)($property['NAME'] ?? '');
        $label = $settings['LABEL'] !== '' ? $settings['LABEL'] : $propertyName;

        return [
            'ID' => (int)$property['ID'],
            'IBLOCK_ID' => (int)$property['IBLOCK_ID'],
            'CODE' => $code,
            'NAME' => $propertyName,
            'SORT' => (int)$property['SORT'],
            'TYPE' => $settings['FIELD_TYPE'],
            'LABEL' => $label,
            'PLACEHOLDER' => $settings['PLACEHOLDER'],
            'REQUIRED' => $settings['REQUIRED'] === 'Y' || ($property['IS_REQUIRED'] ?? 'N') === 'Y',
            'OPTIONS' => $settings['OPTIONS'],
            'MULTIPLE' => ($property['MULTIPLE'] ?? 'N') === 'Y',
        ];
    }
}
