<?php
namespace Aikos\TireBooking;

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

class CenterManager
{
    protected static $module_id = 'aikos.tirebooking';

    public static function getCenterIblockId()
    {
        return (int)Option::get(self::$module_id, 'IBLOCK_CENTER_ID', 38);
    }

    public static function getCenters()
    {
        if (!Loader::includeModule('iblock')) {
            return [];
        }

        $iblockId = self::getCenterIblockId();
        $res = \CIBlockElement::GetList(
            ['NAME' => 'ASC'],
            ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'],
            false,
            false,
            ['ID', 'NAME', 'CODE']
        );

        $centers = [];
        while ($item = $res->GetNext()) {
            $centers[$item['ID']] = $item['NAME'];
        }

        return $centers;
    }
}
