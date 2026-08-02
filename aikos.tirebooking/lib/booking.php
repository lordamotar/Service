<?php
namespace Aikos\TireBooking;

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

class BookingManager
{
    protected static $module_id = 'aikos.tirebooking';

    public static function getBookingIblockId()
    {
        $id = (int)Option::get(self::$module_id, 'IBLOCK_BOOKING_ID', 80);
        return $id > 0 ? $id : 80;
    }

    /**
     * Проверка, является ли строка сервисным типом авто/услуги (не гос. номер)
     */
    protected static function isServiceTypeStr($val)
    {
        $v = mb_strtolower(trim($val));
        $badTypes = ['tyre', 'tire', 'crossover', 'suv', 'truck', 'wheel', 'sedan', 'hatchback', 'service', 'shinomontazh', 'montazh'];
        return in_array($v, $badTypes);
    }

    /**
     * Извлечение данных элемента из свойств инфоблока
     */
    protected static function parseElementData(\_CIBElement $ob)
    {
        $fields = $ob->GetFields();
        $props = $ob->GetProperties();

        $clientName = '';
        $clientPhone = '';
        $carNumber = '';
        $centerId = '';
        $centerName = '';
        $serviceName = '';
        $postName = '';
        $dateTime = '';
        $status = 'Новая';
        $comment = $fields['PREVIEW_TEXT'] ?? $fields['DETAIL_TEXT'] ?? '';

        foreach ($props as $code => $prop) {
            $val = $prop['VALUE'];
            if (is_array($val)) {
                $val = implode(', ', array_filter($val));
            }
            $valStr = trim((string)$val);
            if ($valStr === '') continue;

            $cUpper = mb_strtoupper($code);
            $nameUpper = mb_strtoupper($prop['NAME']);

            // Игнорируем попадание сервисных служебных терминов в гос. номер
            if (self::isServiceTypeStr($valStr)) {
                if (!$serviceName && (strpos($cUpper, 'TYPE') !== false || strpos($cUpper, 'SERVICE') !== false || strpos($nameUpper, 'ТИП') !== false || strpos($nameUpper, 'УСЛУГ') !== false)) {
                    $serviceName = $valStr;
                }
                continue;
            }

            // Поиск телефона
            if (!$clientPhone && (strpos($cUpper, 'PHONE') !== false || strpos($cUpper, 'TEL') !== false || strpos($nameUpper, 'ТЕЛЕФОН') !== false || strpos($nameUpper, 'ТЕЛ') !== false)) {
                $clientPhone = $valStr;
            }
            // Поиск имени клиента / ФИО
            elseif (!$clientName && (strpos($cUpper, 'FIO') !== false || strpos($cUpper, 'CLIENT') !== false || strpos($cUpper, 'USER') !== false || strpos($nameUpper, 'ФИО') !== false || strpos($nameUpper, 'КЛИЕНТ') !== false || strpos($nameUpper, 'ИМЯ') !== false)) {
                $clientName = $valStr;
            }
            // Поиск гос. номера авто (проверяем, чтобы это был не просто тип авто)
            elseif (!$carNumber && (strpos($cUpper, 'CAR_NUMBER') !== false || strpos($cUpper, 'GOS_NUMBER') !== false || strpos($cUpper, 'AUTO_NUM') !== false || strpos($cUpper, 'GOS_NUM') !== false || strpos($cUpper, 'CAR') !== false || strpos($cUpper, 'AUTO') !== false || strpos($nameUpper, 'ГОС') !== false || strpos($nameUpper, 'НОМЕР') !== false)) {
                if (!self::isServiceTypeStr($valStr)) {
                    $carNumber = $valStr;
                }
            }
            // Поиск Центра / Точки / Магазина
            elseif (!$centerId && (strpos($cUpper, 'CENTER') !== false || strpos($cUpper, 'SHOP') !== false || strpos($cUpper, 'STORE') !== false || strpos($cUpper, 'POINT') !== false || strpos($nameUpper, 'ЦЕНТР') !== false || strpos($nameUpper, 'ТОЧКА') !== false || strpos($nameUpper, 'ФИЛИАЛ') !== false)) {
                $centerId = $valStr;
                if (!empty($prop['VALUE_ENUM'])) {
                    $centerName = $prop['VALUE_ENUM'];
                }
            }
            // Поиск даты и времени
            elseif (!$dateTime && (strpos($cUpper, 'DATE') !== false || strpos($cUpper, 'TIME') !== false || strpos($nameUpper, 'ДАТА') !== false || strpos($nameUpper, 'ВРЕМЯ') !== false)) {
                $dateTime = $valStr;
            }
            // Поиск статуса
            elseif (strpos($cUpper, 'STATUS') !== false || strpos($cUpper, 'STATE') !== false || strpos($nameUpper, 'СТАТУС') !== false) {
                $status = $prop['VALUE_ENUM'] ?? $valStr;
            }
            // Поиск услуги
            elseif (!$serviceName && (strpos($cUpper, 'SERVICE') !== false || strpos($nameUpper, 'УСЛУГ') !== false)) {
                $serviceName = $prop['VALUE_ENUM'] ?? $valStr;
            }
            // Поиск поста
            elseif (!$postName && (strpos($cUpper, 'POST') !== false || strpos($nameUpper, 'ПОСТ') !== false)) {
                $postName = $prop['VALUE_ENUM'] ?? $valStr;
            }
        }

        // Резервные значения из имени и даты элемента
        if (!$clientName) {
            $clientName = $fields['NAME'];
        }
        if (!$dateTime) {
            $dateTime = $fields['DATE_CREATE'];
        }

        return [
            'ID' => $fields['ID'],
            'NAME' => $fields['NAME'],
            'DATE_CREATE' => $fields['DATE_CREATE'],
            'CENTER_ID' => $centerId,
            'CENTER_NAME' => $centerName,
            'SERVICE_NAME' => $serviceName,
            'POST_NAME' => $postName,
            'DATE_TIME' => $dateTime,
            'CLIENT_NAME' => $clientName,
            'CLIENT_PHONE' => $clientPhone,
            'CAR_NUMBER' => self::isServiceTypeStr($carNumber) ? '' : $carNumber,
            'STATUS' => $status,
            'COMMENT' => $comment
        ];
    }

    /**
     * Получить весь выбор записей из инфоблока 80 или 94
     */
    public static function getList(array $filter = [], array $order = ['ID' => 'DESC'], $limit = 2000)
    {
        if (!Loader::includeModule('iblock')) {
            return [];
        }

        $iblockId = self::getBookingIblockId();
        $arFilter = ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'];

        $res = \CIBlockElement::GetList(
            $order,
            $arFilter,
            false,
            $limit > 0 ? ['nTopCount' => $limit] : false,
            []
        );

        $items = [];
        while ($ob = $res->GetNextElement()) {
            $items[] = self::parseElementData($ob);
        }

        if (empty($items) && $iblockId == 80) {
            $arFilter['IBLOCK_ID'] = 94;
            $res = \CIBlockElement::GetList($order, $arFilter, false, ['nTopCount' => $limit], []);
            while ($ob = $res->GetNextElement()) {
                $items[] = self::parseElementData($ob);
            }
        }

        return $items;
    }

    public static function getStats()
    {
        $items = self::getList([], ['ID' => 'DESC'], 1000);

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
