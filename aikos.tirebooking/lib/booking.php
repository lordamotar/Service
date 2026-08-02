<?php
namespace Aikos\TireBooking;

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Config\Option;

class BookingManager
{
    protected static $module_id = 'aikos.tirebooking';

    /**
     * Получить ID инфоблока записей
     */
    public static function getBookingIblockId()
    {
        return (int)Option::get(self::$module_id, 'IBLOCK_BOOKING_ID', 80);
    }

    /**
     * Получить список записей с фильтрацией
     */
    public static function getList(array $filter = [], array $order = ['ID' => 'DESC'], $limit = 50)
    {
        if (!Loader::includeModule('iblock')) {
            return [];
        }

        $iblockId = self::getBookingIblockId();
        $arFilter = array_merge(['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'], $filter);

        $res = \CIBlockElement::GetList(
            $order,
            $arFilter,
            false,
            ['nTopCount' => $limit],
            [
                'ID', 'IBLOCK_ID', 'NAME', 'DATE_CREATE', 'CODE', 'ACTIVE',
                'PROPERTY_CENTER_ID', 'PROPERTY_SERVICE_ID', 'PROPERTY_POST_ID',
                'PROPERTY_DATE_TIME', 'PROPERTY_CLIENT_NAME', 'PROPERTY_CLIENT_PHONE',
                'PROPERTY_CAR_NUMBER', 'PROPERTY_STATUS', 'PROPERTY_COMMENT'
            ]
        );

        $items = [];
        while ($ob = $res->GetNextElement()) {
            $fields = $ob->GetFields();
            $props = $ob->GetProperties();

            $items[] = [
                'ID' => $fields['ID'],
                'NAME' => $fields['NAME'],
                'DATE_CREATE' => $fields['DATE_CREATE'],
                'CENTER_ID' => $props['CENTER_ID']['VALUE'] ?? $fields['PROPERTY_CENTER_ID_VALUE'] ?? '',
                'SERVICE_ID' => $props['SERVICE_ID']['VALUE'] ?? $fields['PROPERTY_SERVICE_ID_VALUE'] ?? '',
                'POST_ID' => $props['POST_ID']['VALUE'] ?? $fields['PROPERTY_POST_ID_VALUE'] ?? '',
                'DATE_TIME' => $props['DATE_TIME']['VALUE'] ?? $fields['PROPERTY_DATE_TIME_VALUE'] ?? $fields['DATE_CREATE'],
                'CLIENT_NAME' => $props['CLIENT_NAME']['VALUE'] ?? $fields['PROPERTY_CLIENT_NAME_VALUE'] ?? $fields['NAME'],
                'CLIENT_PHONE' => $props['CLIENT_PHONE']['VALUE'] ?? $fields['PROPERTY_CLIENT_PHONE_VALUE'] ?? '',
                'CAR_NUMBER' => $props['CAR_NUMBER']['VALUE'] ?? $fields['PROPERTY_CAR_NUMBER_VALUE'] ?? '',
                'STATUS' => $props['STATUS']['VALUE_ENUM'] ?? $props['STATUS']['VALUE'] ?? $fields['PROPERTY_STATUS_VALUE'] ?? 'Новая',
                'COMMENT' => $fields['PREVIEW_TEXT'] ?? $props['COMMENT']['VALUE'] ?? ''
            ];
        }

        return $items;
    }

    /**
     * Получить сводную статистику записей по статусам за текущие сутки / период
     */
    public static function getStats($dateFrom = null, $dateTo = null, $centerId = null)
    {
        if (!Loader::includeModule('iblock')) {
            return [
                'total' => 0,
                'new' => 0,
                'confirmed' => 0,
                'in_progress' => 0,
                'completed' => 0,
                'cancelled' => 0
            ];
        }

        $filter = [];
        if ($centerId > 0) {
            $filter['PROPERTY_CENTER_ID'] = $centerId;
        }

        if ($dateFrom) {
            $filter['>=DATE_CREATE'] = $dateFrom;
        }
        if ($dateTo) {
            $filter['<=DATE_CREATE'] = $dateTo;
        }

        $items = self::getList($filter, ['ID' => 'DESC'], 1000);

        $stats = [
            'total' => count($items),
            'new' => 0,
            'confirmed' => 0,
            'in_progress' => 0,
            'completed' => 0,
            'cancelled' => 0
        ];

        foreach ($items as $item) {
            $status = mb_strtolower(trim($item['STATUS']));
            if (strpos($status, 'нов') !== false) {
                $stats['new']++;
            } elseif (strpos($status, 'подтвержд') !== false) {
                $stats['confirmed']++;
            } elseif (strpos($status, 'работ') !== false || strpos($status, 'процесс') !== false) {
                $stats['in_progress']++;
            } elseif (strpos($status, 'заверш') !== false || strpos($status, 'выполн') !== false) {
                $stats['completed']++;
            } elseif (strpos($status, 'отмен') !== false) {
                $stats['cancelled']++;
            } else {
                $stats['new']++;
            }
        }

        return $stats;
    }
}
