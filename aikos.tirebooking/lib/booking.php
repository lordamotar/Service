<?php
namespace Aikos\TireBooking;

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Data\Cache;

class BookingManager
{
    protected static $module_id = 'aikos.tirebooking';

    public static function getBookingIblockId()
    {
        $id = (int)Option::get(self::$module_id, 'IBLOCK_BOOKING_ID', 80);
        return $id > 0 ? $id : 80;
    }

    protected static function isServiceTypeStr($val)
    {
        $v = mb_strtolower(trim($val));
        $badTypes = ['tyre', 'tire', 'crossover', 'suv', 'truck', 'wheel', 'sedan', 'hatchback', 'service', 'shinomontazh', 'montazh'];
        return in_array($v, $badTypes);
    }

    /**
     * Получить общее количество элементов в БД
     */
    public static function getCount(array $filter = [])
    {
        if (!Loader::includeModule('iblock')) return 0;
        $iblockId = self::getBookingIblockId();
        $arFilter = array_merge(['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'], $filter);

        $cache = Cache::createInstance();
        $cacheId = 'b_cnt_' . md5(serialize($arFilter));
        if ($cache->initCache(300, $cacheId, '/aikos_tirebooking/counts/')) {
            return $cache->getVars();
        }

        $count = (int)\CIBlockElement::GetList([], $arFilter, array());
        if ($count === 0 && $iblockId == 80 && empty($filter)) {
            $arFilter['IBLOCK_ID'] = 94;
            $count = (int)\CIBlockElement::GetList([], $arFilter, array());
        }

        if ($cache->startDataCache()) {
            $cache->endDataCache($count);
        }
        return $count;
    }

    /**
     * Высокоскоростной распарсинг элементов без отдельных SQL запросов на свойства
     */
    protected static function parseFieldsFast(array $fields)
    {
        $clientName = $fields['NAME'] ?? '';
        $clientPhone = '';
        $carNumber = '';
        $centerId = '';
        $centerName = '';
        $serviceName = '';
        $postName = '';
        $dateTime = $fields['DATE_CREATE'] ?? '';
        $status = 'Новая';
        $comment = $fields['PREVIEW_TEXT'] ?? $fields['DETAIL_TEXT'] ?? '';

        foreach ($fields as $key => $valStr) {
            if (strpos($key, 'PROPERTY_') === false) continue;
            if (is_array($valStr)) $valStr = implode(', ', array_filter($valStr));
            $valStr = trim((string)$valStr);
            if ($valStr === '') continue;

            $kUpper = mb_strtoupper($key);

            if (self::isServiceTypeStr($valStr)) {
                if (!$serviceName && (strpos($kUpper, 'TYPE') !== false || strpos($kUpper, 'SERVICE') !== false)) {
                    $serviceName = $valStr;
                }
                continue;
            }

            if (!$clientPhone && (strpos($kUpper, 'PHONE') !== false || strpos($kUpper, 'TEL') !== false)) {
                $clientPhone = $valStr;
            }
            elseif (!$clientName && (strpos($kUpper, 'FIO') !== false || strpos($kUpper, 'CLIENT') !== false || strpos($kUpper, 'USER') !== false)) {
                $clientName = $valStr;
            }
            elseif (!$carNumber && (strpos($kUpper, 'CAR') !== false || strpos($kUpper, 'GOS') !== false || strpos($kUpper, 'AUTO') !== false || strpos($kUpper, 'NUM') !== false)) {
                if (!self::isServiceTypeStr($valStr)) {
                    $carNumber = $valStr;
                }
            }
            elseif (!$centerId && (strpos($kUpper, 'CENTER') !== false || strpos($kUpper, 'SHOP') !== false || strpos($kUpper, 'STORE') !== false || strpos($kUpper, 'POINT') !== false)) {
                $centerId = $valStr;
            }
            elseif (!$dateTime && (strpos($kUpper, 'DATE') !== false || strpos($kUpper, 'TIME') !== false)) {
                $dateTime = $valStr;
            }
            elseif (strpos($kUpper, 'STATUS') !== false || strpos($kUpper, 'STATE') !== false) {
                $status = $valStr;
            }
        }

        return [
            'ID' => $fields['ID'],
            'NAME' => $fields['NAME'],
            'DATE_CREATE' => $fields['DATE_CREATE'],
            'CENTER_ID' => $centerId,
            'CENTER_NAME' => $centerName,
            'SERVICE_NAME' => $serviceName,
            'POST_NAME' => $postName,
            'DATE_TIME' => $dateTime ?: $fields['DATE_CREATE'],
            'CLIENT_NAME' => $clientName,
            'CLIENT_PHONE' => $clientPhone,
            'CAR_NUMBER' => self::isServiceTypeStr($carNumber) ? '' : $carNumber,
            'STATUS' => $status,
            'COMMENT' => $comment
        ];
    }

    /**
     * Высокоскоростной метод получения списка с кешированием и пакетированной выборкой
     */
    public static function getList(array $filter = [], array $order = ['ID' => 'DESC'], $limit = 5000)
    {
        if (!Loader::includeModule('iblock')) return [];

        $iblockId = self::getBookingIblockId();
        
        // Попытка получить данные из кеша
        $cache = Cache::createInstance();
        $cacheId = 'b_list_' . md5(serialize([$filter, $order, $limit, $iblockId]));
        if ($cache->initCache(180, $cacheId, '/aikos_tirebooking/list/')) {
            return $cache->getVars();
        }

        $arFilter = array_merge(['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'], $filter);

        // Формируем массовую выборку элемента со всеми полями PROPERTY_* за 1 SQL запрос
        $res = \CIBlockElement::GetList(
            $order,
            $arFilter,
            false,
            $limit > 0 ? ['nTopCount' => $limit] : false,
            ['ID', 'NAME', 'DATE_CREATE', 'PREVIEW_TEXT', 'DETAIL_TEXT', 'PROPERTY_*']
        );

        $items = [];
        while ($ob = $res->GetNextElement()) {
            $fields = $ob->GetFields();
            $props = $ob->GetProperties();
            
            // Заполняем виртуальные поля PROPERTY_* из свойств для быстрой обработки
            foreach ($props as $pCode => $pData) {
                $fields['PROPERTY_' . $pCode . '_VALUE'] = $pData['VALUE'];
            }
            
            $items[] = self::parseFieldsFast($fields);
        }

        if (empty($items) && $iblockId == 80 && empty($filter)) {
            $arFilter['IBLOCK_ID'] = 94;
            $res = \CIBlockElement::GetList($order, $arFilter, false, ['nTopCount' => $limit], ['ID', 'NAME', 'DATE_CREATE', 'PREVIEW_TEXT', 'DETAIL_TEXT', 'PROPERTY_*']);
            while ($ob = $res->GetNextElement()) {
                $fields = $ob->GetFields();
                $props = $ob->GetProperties();
                foreach ($props as $pCode => $pData) {
                    $fields['PROPERTY_' . $pCode . '_VALUE'] = $pData['VALUE'];
                }
                $items[] = self::parseFieldsFast($fields);
            }
        }

        if ($cache->startDataCache()) {
            $cache->endDataCache($items);
        }

        return $items;
    }
}
