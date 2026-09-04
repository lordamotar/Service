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

    /*
    // Создание новой записи бронирования шиномонтажа
    public static function createBooking(array $data)
    {
        if (!Loader::includeModule('iblock')) {
            return ['success' => false, 'error' => 'Модуль Инфоблоков не подключен'];
        }

        $iblockId = self::getBookingIblockId();
        
        $clientName = trim((string)($data['CLIENT_NAME'] ?? ''));
        $rawPhone = preg_replace('/[^\d]/', '', (string)($data['CLIENT_PHONE'] ?? ''));
        if (strlen($rawPhone) === 11 && ($rawPhone[0] === '8' || $rawPhone[0] === '7')) {
            $clientPhone = '7' . substr($rawPhone, 1);
        } else {
            $clientPhone = $rawPhone;
        }
        $carNumber = trim((string)($data['CAR_NUMBER'] ?? ''));
        $bodyType = trim((string)($data['BODY_TYPE'] ?? 'Легковой'));
        $tireDiameter = trim((string)($data['TIRE_DIAMETER'] ?? 'R16'));
        $visitDate = trim((string)($data['VISIT_DATE'] ?? ''));
        $isStorage = trim((string)($data['IS_STORAGE'] ?? 'Нет'));
        $storageContract = trim((string)($data['STORAGE_CONTRACT'] ?? ''));
        $notification = trim((string)($data['NOTIFICATION_STATUS'] ?? 'Ожидание'));
        $iin = trim((string)($data['IIN'] ?? ''));
        $centerId = (int)($data['CENTER_ID'] ?? 0);
        $postName = trim((string)($data['POST_NAME'] ?? 'пост 1'));
        $comment = trim((string)($data['COMMENT'] ?? ''));

        if (empty($visitDate)) {
            $visitDate = date('d.m.Y H:i:s');
        } else {
            $ts = strtotime($visitDate);
            if ($ts) {
                $visitDate = date('d.m.Y H:i:s', $ts);
            }
        }

        // Получаем название шинного центра для формирования интервала
        $centers = CenterManager::getCenters();
        $centerTitle = $centers[$centerId] ?? '';
        if (empty($centerTitle) && $centerId > 0) {
            $cRes = \CIBlockElement::GetByID($centerId)->Fetch();
            if ($cRes) {
                $centerTitle = $cRes['NAME'];
            }
        }
        if (empty($centerTitle)) {
            $centerTitle = 'Шинный центр';
        }

        // Формируем точную строку интервала для сайта (например: "г. Семей ул. Бозтаева 106 Пост 1 - 01.11.2026 12:00:00 - Шиномонтажные услуги")
        $postNum = preg_replace('/[^\d]/', '', $postName) ?: '1';
        $postFormatted = 'Пост ' . $postNum;
        $reservedIntervalStr = trim($centerTitle) . ' ' . $postFormatted . ' - ' . $visitDate . ' - Шиномонтажные услуги';

        $el = new \CIBlockElement();
        $propValues = [];

        // Автоматическая привязка к свойствам инфоблока
        $dbProps = \CIBlockProperty::GetList(['SORT' => 'ASC'], ['IBLOCK_ID' => $iblockId]);
        while ($p = $dbProps->Fetch()) {
            $pCode = mb_strtoupper((string)$p['CODE']);
            $pName = mb_strtolower(trim((string)$p['NAME']), 'UTF-8');
            $pId = $p['ID'];
            $pType = $p['PROPERTY_TYPE'];
            $pMultiple = ($p['MULTIPLE'] === 'Y');
            $targetKey = !empty($pCode) ? $pCode : $pId;

            // 1. ЗАБРОНИРОВАННЫЕ ИНТЕРВАЛЫ (главное свойство для блокировки времени на сайте)
            if (mb_strpos($pName, 'забронированные') !== false || in_array($pCode, ['RESERVED_INTERVALS', 'BOOKED_INTERVALS', 'INTERVALS', 'BOOKING_INTERVALS'])) {
                $propValues[$targetKey] = [$reservedIntervalStr];
                continue;
            }

            // 2. НАЧАЛО ПЕРВОГО ИНТЕРВАЛА (дата и время визита)
            if (mb_strpos($pName, 'начало первого интервала') !== false || mb_strpos($pName, 'первый интервал') !== false || mb_strpos($pName, 'начало') !== false || in_array($pCode, ['TIME_FIRST_INTERVAL', 'FIRST_INTERVAL'])) {
                $propValues[$targetKey] = $visitDate;
                continue;
            }

            // 3. Имя и фамилия клиента (ФИО)
            if (mb_strpos($pName, 'имя и фамилия') !== false || mb_strpos($pName, 'имя клиента') !== false || in_array($pCode, ['FIO', 'CLIENT_NAME', 'USER_NAME', 'NAME_CLIENT', 'CLIENT_FIO', 'NAME_AND_SURNAME', 'FULL_NAME', 'CUSTOMER', 'NAME_SURNAME', 'FIRST_NAME', 'LAST_NAME'])
                || (mb_strpos($pName, 'имя') !== false && mb_strpos($pName, 'файл') === false)
                || (mb_strpos($pName, 'клиент') !== false && mb_strpos($pName, 'уведомл') === false && mb_strpos($pName, 'тип') === false)) {
                $propValues[$targetKey] = $clientName;
                continue;
            }

            // 4. Телефон
            if (mb_strpos($pName, 'телефон') !== false || mb_strpos($pName, 'тел') !== false || in_array($pCode, ['PHONE', 'TEL', 'CLIENT_PHONE', 'TELEPHONE', 'MOBILE'])) {
                $propValues[$targetKey] = $clientPhone;
                continue;
            }

            // 5. Госномер авто
            if (mb_strpos($pName, 'гос. номер') !== false || mb_strpos($pName, 'госномер') !== false || mb_strpos($pName, 'номер авто') !== false || in_array($pCode, ['CAR_NUMBER', 'GOS_NUMBER', 'GOS_NUM', 'STATE_NUMBER', 'AUTO_NUM', 'CAR', 'GOS', 'STATE_NUM', 'AUTO_NUMBER'])) {
                $propValues[$targetKey] = $carNumber;
                continue;
            }

            // 6. Тип кузова авто (сопоставление со списком ENUM)
            if (mb_strpos($pName, 'тип кузова') !== false || mb_strpos($pName, 'тип авто') !== false || mb_strpos($pName, 'кузов') !== false || in_array($pCode, ['BODY_TYPE', 'CAR_BODY', 'AUTO_TYPE', 'BODY', 'TYPE_AUTO', 'CAR_TYPE', 'KUZOV', 'CAR_CLASS', 'AUTO_CLASS', 'BODYTYPE', 'VEHICLE_TYPE', 'CAR_BODY_TYPE', 'KUZOV_TYPE', 'TYPE_CAR', 'CLASS'])) {
                if ($pType === 'L') {
                    $matchedId = 0;
                    $dbEnums = \CIBlockPropertyEnum::GetList(['SORT' => 'ASC'], ['PROPERTY_ID' => $pId]);
                    while ($enum = $dbEnums->Fetch()) {
                        $eVal = mb_strtolower(trim($enum['VALUE']), 'UTF-8');
                        $eXml = mb_strtolower(trim($enum['XML_ID']), 'UTF-8');
                        $bLower = mb_strtolower(trim($bodyType), 'UTF-8');
                        if ($eVal === $bLower || $eXml === $bLower || ($bLower === 'кроссовер' && (strpos($eVal, 'кросс') !== false || strpos($eVal, 'cross') !== false)) || ($bLower === 'легковой' && (strpos($eVal, 'легк') !== false || strpos($eVal, 'седан') !== false || strpos($eVal, 'tyre') !== false)) || ($bLower === 'внедорожник' && (strpos($eVal, 'внедор') !== false || strpos($eVal, 'suv') !== false))) {
                            $matchedId = $enum['ID'];
                            break;
                        }
                    }
                    $propValues[$targetKey] = $matchedId > 0 ? $matchedId : $bodyType;
                } else {
                    $propValues[$targetKey] = $bodyType;
                }
                continue;
            }

            // 7. Диаметр шин (сопоставление со списком ENUM)
            if (mb_strpos($pName, 'диаметр шин') !== false || mb_strpos($pName, 'размер шин') !== false || (mb_strpos($pName, 'диаметр') !== false && mb_strpos($pName, 'центр') === false) || in_array($pCode, ['TIRE_DIAMETER', 'SHIN_DIAMETER', 'DIAMETER', 'WHEEL_SIZE', 'TIRE_SIZE', 'R_SIZE', 'DIAMETR'])) {
                if ($pType === 'L') {
                    $matchedId = 0;
                    $dbEnums = \CIBlockPropertyEnum::GetList(['SORT' => 'ASC'], ['PROPERTY_ID' => $pId]);
                    $tNum = preg_replace('/[^0-9]/', '', $tireDiameter);
                    while ($enum = $dbEnums->Fetch()) {
                        $eVal = mb_strtolower(trim($enum['VALUE']), 'UTF-8');
                        $eNum = preg_replace('/[^0-9]/', '', $eVal);
                        if ($eNum === $tNum && !empty($tNum)) {
                            $matchedId = $enum['ID'];
                            break;
                        }
                    }
                    $propValues[$targetKey] = $matchedId > 0 ? $matchedId : $tireDiameter;
                } else {
                    $propValues[$targetKey] = $tireDiameter;
                }
                continue;
            }

            // 8. ACCEPT (подтверждение брони для блокировки на сайте)
            if (mb_strpos($pName, 'accept') !== false || in_array($pCode, ['ACCEPT', 'ACCEPTED', 'CONFIRMED', 'STATUS_ACCEPT'])) {
                if ($pType === 'L') {
                    $enumRes = \CIBlockPropertyEnum::GetList([], ['PROPERTY_ID' => $pId, 'VALUE' => 'Да'])->Fetch();
                    $propValues[$targetKey] = $enumRes ? $enumRes['ID'] : 'Да';
                } else {
                    $propValues[$targetKey] = 'Да';
                }
                continue;
            }

            // 9. Хранение шин
            if (mb_strpos($pName, 'храните шины') !== false || mb_strpos($pName, 'хранение') !== false || in_array($pCode, ['STORAGE', 'IS_STORAGE', 'KHRANENIE'])) {
                if ($pType === 'L') {
                    $matchedId = 0;
                    $dbEnums = \CIBlockPropertyEnum::GetList(['SORT' => 'ASC'], ['PROPERTY_ID' => $pId]);
                    while ($enum = $dbEnums->Fetch()) {
                        if (mb_strtolower(trim($enum['VALUE']), 'UTF-8') === mb_strtolower(trim($isStorage), 'UTF-8')) {
                            $matchedId = $enum['ID'];
                            break;
                        }
                    }
                    $propValues[$targetKey] = $matchedId > 0 ? $matchedId : $isStorage;
                } else {
                    $propValues[$targetKey] = $isStorage;
                }
                continue;
            }

            // 10. Номер договора хранения
            if (mb_strpos($pName, 'номер договора') !== false || mb_strpos($pName, 'договор хранения') !== false || in_array($pCode, ['STORAGE_CONTRACT', 'CONTRACT_NUMBER', 'CONTRACT_NUM', 'DOGOVOR_STORAGE', 'NUM_DOGOVOR', 'STORAGE_NUM'])) {
                $propValues[$targetKey] = $storageContract;
                continue;
            }

            // 11. Уведомление клиента за час
            if (mb_strpos($pName, 'уведомление') !== false || mb_strpos($pName, 'уведомлен') !== false || mb_strpos($pName, 'оповещ') !== false || in_array($pCode, ['NOTIFICATION_STATUS', 'NOTIFY', 'NOTIFICATION', 'NOTIF', 'NOTIFIED', 'NOTIFY_STATUS', 'STATUS_NOTIFY'])) {
                if ($pType === 'L') {
                    $matchedId = 0;
                    $dbEnums = \CIBlockPropertyEnum::GetList(['SORT' => 'ASC'], ['PROPERTY_ID' => $pId]);
                    while ($enum = $dbEnums->Fetch()) {
                        if (mb_strtolower(trim($enum['VALUE']), 'UTF-8') === mb_strtolower(trim($notification), 'UTF-8')) {
                            $matchedId = $enum['ID'];
                            break;
                        }
                    }
                    $propValues[$targetKey] = $matchedId > 0 ? $matchedId : $notification;
                } else {
                    $propValues[$targetKey] = $notification;
                }
                continue;
            }

            // 12. ИИН
            if (mb_strpos($pName, 'иин') !== false || in_array($pCode, ['IIN', 'BIN', 'CLIENT_IIN', 'CUSTOMER_IIN'])) {
                $propValues[$targetKey] = $iin;
                continue;
            }

            // 13. Шинный центр
            if (mb_strpos($pName, 'центр') !== false || mb_strpos($pName, 'магазин') !== false || in_array($pCode, ['CENTER', 'CENTER_ID', 'SHOP', 'STORE', 'POINT'])) {
                $propValues[$targetKey] = $centerId;
                continue;
            }
        }

        $arFields = [
            'IBLOCK_ID' => $iblockId,
            'NAME' => $notification ?: 'Ожидание',
            'ACTIVE' => 'Y',
            'DATE_ACTIVE_FROM' => date('d.m.Y H:i:s'),
            'PREVIEW_TEXT' => $comment,
            'DETAIL_TEXT' => $comment,
            'PROPERTY_VALUES' => $propValues,
        ];

        $newId = (int)$el->Add($arFields);

        if ($newId > 0) {
            // Принудительно и гарантированно перезаписываем свойства через SetPropertyValuesEx
            \CIBlockElement::SetPropertyValuesEx($newId, $iblockId, $propValues);

            // Очищаем кэш инфоблока и модуля
            if (class_exists('\CIBlock')) {
                \CIBlock::clearIblockTagCache($iblockId);
            }
            if (function_exists('BXClearCache')) {
                \BXClearCache(true);
            }
            $cache = Cache::createInstance();
            $cache->cleanDir('/aikos_tirebooking/');

            return ['success' => true, 'id' => $newId];
        }

        return ['success' => false, 'error' => $el->LAST_ERROR ?: 'Ошибка при сохранении записи'];
    }
    */

    public static function getStats(array $filter = [])
    {
        $cache = Cache::createInstance();
        $cacheId = 'b_stats_v1_' . md5(serialize($filter));
        if ($cache->initCache(180, $cacheId, '/aikos_tirebooking/stats/')) {
            return $cache->getVars();
        }

        $items = self::getList($filter, ['ID' => 'DESC'], 5000);
        $total = self::getCount($filter);
        if ($total == 0) $total = count($items);

        $new = 0;
        $inProgress = 0;
        $completed = 0;

        foreach ($items as $it) {
            $st = mb_strtolower($it['STATUS']);
            if (strpos($st, 'заверш') !== false || strpos($st, 'выполн') !== false || strpos($st, 'готов') !== false) {
                $completed++;
            } elseif (strpos($st, 'работ') !== false || strpos($st, 'процесс') !== false || strpos($st, 'подтвержд') !== false) {
                $inProgress++;
            } else {
                $new++;
            }
        }

        $result = [
            'total' => $total,
            'new' => $new,
            'in_progress' => $inProgress,
            'completed' => $completed,
        ];

        if ($cache->startDataCache()) {
            $cache->endDataCache($result);
        }

        return $result;
    }

    /**
     * Высокоскоростной распарсинг элементов без отдельных SQL запросов на свойства
     */
    protected static function parseFieldsFast(array $fields, array $props = [])
    {
        $rawElementName = trim((string)($fields['NAME'] ?? ''));
        $clientName = '';
        $clientPhone = '';
        $carNumber = '';
        $bodyType = '';
        $tireDiameter = '';
        $isStorage = '';
        $storageContract = '';
        $notificationStatus = '';
        $iin = '';
        $centerId = '';
        $centerName = '';
        $serviceName = '';
        $postName = '';
        $timeFirstInterval = '';
        $dateTime = $fields['DATE_CREATE'] ?? '';
        $status = 'Новая';
        $comment = $fields['PREVIEW_TEXT'] ?? $fields['DETAIL_TEXT'] ?? '';

        // Проверяем, является ли NAME элемента служебным статусом уведомления (напр. "Ожидание", "Уведомлен")
        if (in_array(mb_strtolower($rawElementName), ['ожидание', 'уведомлен', 'уведомлено', 'отправлено', 'доставлено', 'ошибка', 'новая'])) {
            $notificationStatus = $rawElementName;
        } else {
            $clientName = $rawElementName;
        }

        // 1. Точный поиск по структурированным свойствам ($props)
        if (!empty($props)) {
            foreach ($props as $pCode => $pData) {
                $codeUpper = mb_strtoupper((string)$pCode);
                $nameLower = mb_strtolower(trim((string)($pData['NAME'] ?? '')));
                
                $val = !empty($pData['VALUE_ENUM']) ? $pData['VALUE_ENUM'] : $pData['VALUE'];
                if (is_array($val)) {
                    $val = implode(', ', array_filter($val));
                }
                $valStr = trim((string)$val);
                if ($valStr === '') continue;

                // Госномер автомобиля
                if (!$carNumber) {
                    if (in_array($codeUpper, ['CAR_NUMBER', 'GOS_NUMBER', 'GOS_NUM', 'STATE_NUMBER', 'AUTO_NUM', 'CAR', 'GOS', 'STATE_NUM', 'AUTO_NUMBER'])
                        || strpos($nameLower, 'гос. номер') !== false
                        || strpos($nameLower, 'госномер') !== false
                        || strpos($nameLower, 'гос номер') !== false
                        || strpos($nameLower, 'номер авто') !== false) {
                        if (!self::isServiceTypeStr($valStr) && $valStr !== '500') {
                            $carNumber = $valStr;
                        }
                    }
                }

                // Тип кузова
                if (!$bodyType) {
                    if (in_array($codeUpper, ['BODY_TYPE', 'CAR_BODY', 'AUTO_TYPE', 'BODY', 'TYPE_AUTO', 'CAR_TYPE', 'KUZOV', 'CAR_CLASS', 'AUTO_CLASS', 'BODYTYPE', 'VEHICLE_TYPE', 'CAR_BODY_TYPE', 'KUZOV_TYPE', 'TYPE_CAR', 'CLASS'])
                        || strpos($nameLower, 'тип кузова') !== false
                        || strpos($nameLower, 'тип авто') !== false
                        || strpos($nameLower, 'тип автомобиля') !== false
                        || strpos($nameLower, 'класс авто') !== false
                        || strpos($nameLower, 'кузов') !== false) {
                        $bodyType = $valStr;
                    }
                }

                // Диаметр шин
                if (!$tireDiameter) {
                    if (in_array($codeUpper, ['TIRE_DIAMETER', 'SHIN_DIAMETER', 'DIAMETER', 'WHEEL_SIZE', 'TIRE_SIZE', 'R_SIZE', 'DIAMETR'])
                        || strpos($nameLower, 'диаметр шин') !== false
                        || strpos($nameLower, 'размер шин') !== false
                        || (strpos($nameLower, 'диаметр') !== false && strpos($nameLower, 'центр') === false)) {
                        $tireDiameter = $valStr;
                    }
                }

                // Начало первого интервала (дата/время визита)
                if (!$timeFirstInterval) {
                    if (in_array($codeUpper, ['TIME_FIRST_INTERVAL', 'FIRST_INTERVAL', 'DATE_TIME', 'VISIT_TIME', 'DATE_VISIT', 'INTERVAL'])
                        || strpos($nameLower, 'начало первого интервала') !== false
                        || strpos($nameLower, 'первый интервал') !== false
                        || strpos($nameLower, 'время визита') !== false) {
                        $timeFirstInterval = $valStr;
                    }
                }

                // Хранение
                if (!$isStorage) {
                    if (in_array($codeUpper, ['STORAGE', 'IS_STORAGE', 'STORAGE_CONTRACT', 'STORAGE_NUM', 'KHRANENIE'])
                        || strpos($nameLower, 'храните шины') !== false
                        || strpos($nameLower, 'хранение') !== false) {
                        $isStorage = $valStr;
                    }
                }

                // Номер договора хранения
                if (!$storageContract) {
                    if (in_array($codeUpper, ['STORAGE_CONTRACT', 'CONTRACT_NUMBER', 'CONTRACT_NUM', 'DOGOVOR_STORAGE', 'NUM_DOGOVOR', 'STORAGE_NUM'])
                        || strpos($nameLower, 'номер договора') !== false
                        || strpos($nameLower, 'договор хранения') !== false) {
                        $storageContract = $valStr;
                    }
                }

                // Уведомление клиента за час
                if (in_array($codeUpper, ['NOTIFICATION_STATUS', 'NOTIFY', 'NOTIFICATION', 'NOTIF', 'NOTIFIED', 'NOTIFY_STATUS', 'STATUS_NOTIFY'])
                    || mb_strpos($nameLower, 'уведомлен') !== false
                    || mb_strpos($nameLower, 'оповещ') !== false
                    || mb_strpos($nameLower, 'напомин') !== false) {
                    $notificationStatus = $valStr;
                    continue; // Пропускаем, чтобы не перепутать с именем клиента
                }

                // ИИН
                if (!$iin) {
                    if (in_array($codeUpper, ['IIN', 'BIN', 'CLIENT_IIN', 'CUSTOMER_IIN']) || mb_strpos($nameLower, 'иин') !== false) {
                        $iin = $valStr;
                        continue;
                    }
                }

                // ФИО / Клиент (Имя и фамилия)
                $isClientField = false;
                if (in_array($codeUpper, ['FIO', 'CLIENT_NAME', 'USER_NAME', 'NAME_CLIENT', 'CLIENT_FIO', 'NAME_AND_SURNAME', 'FULL_NAME', 'CUSTOMER', 'NAME_SURNAME', 'FIRST_NAME', 'LAST_NAME', 'CONTACT_PERSON', 'CONTACT_NAME', 'CLIENT_NAME_SURNAME', 'NAME_AND_LAST_NAME', 'CLIENT', 'NAME'])
                    || mb_strpos($nameLower, 'имя и фамилия') !== false
                    || mb_strpos($nameLower, 'имя клиента') !== false
                    || mb_strpos($nameLower, 'фио') !== false
                    || mb_strpos($nameLower, 'фамилия') !== false
                    || (mb_strpos($nameLower, 'имя') !== false && mb_strpos($nameLower, 'файл') === false && mb_strpos($nameLower, 'свойств') === false)
                    || (mb_strpos($nameLower, 'клиент') !== false && mb_strpos($nameLower, 'тип') === false && mb_strpos($nameLower, 'уведомл') === false && mb_strpos($nameLower, 'статус') === false && mb_strpos($nameLower, 'оповещ') === false)) {
                    $isClientField = true;
                }

                if ($isClientField) {
                    $valClean = is_array($pData['VALUE']) && isset($pData['VALUE']['TEXT']) ? trim((string)$pData['VALUE']['TEXT']) : $valStr;
                    $valLower = mb_strtolower($valClean);
                    if ($valClean !== '' && !in_array($valLower, ['ожидание', 'уведомлен', 'уведомлено', 'отправлено', 'доставлено', 'ошибка', 'новая', 'в работе', 'завершена', 'отменена', 'нет', 'да'])) {
                        $clientName = $valClean;
                    }
                }

                // Телефон
                if (!$clientPhone) {
                    if (in_array($codeUpper, ['PHONE', 'TEL', 'CLIENT_PHONE', 'TELEPHONE', 'MOBILE'])
                        || strpos($nameLower, 'телефон') !== false
                        || strpos($nameLower, 'тел') !== false) {
                        $clientPhone = $valStr;
                    }
                }

                // Центр
                if (!$centerId) {
                    if (in_array($codeUpper, ['CENTER', 'CENTER_ID', 'SHOP', 'STORE', 'POINT'])
                        || strpos($nameLower, 'центр') !== false
                        || strpos($nameLower, 'магазин') !== false) {
                        $centerId = $valStr;
                    }
                }

                // Статус
                if ($status === 'Новая') {
                    if (in_array($codeUpper, ['STATUS', 'STATE']) || strpos($nameLower, 'статус') !== false) {
                        $status = $valStr;
                    }
                }
            }
        }

        // 1.1 Если имя клиента не определено по стандартным кодам, ищем среди текстовых свойств ФИО
        if (empty($clientName) && !empty($props)) {
            foreach ($props as $pCode => $pData) {
                $val = !empty($pData['VALUE_ENUM']) ? $pData['VALUE_ENUM'] : $pData['VALUE'];
                if (is_array($val)) {
                    $val = isset($val['TEXT']) ? $val['TEXT'] : implode(' ', array_filter($val));
                }
                $valStr = trim((string)$val);
                if ($valStr === '') continue;

                $valLower = mb_strtolower($valStr);
                // Исключаем служебные статусы, числа, телефоны, кузова, даты, адреса
                if (in_array($valLower, ['ожидание', 'уведомлен', 'уведомлено', 'отправлено', 'доставлено', 'ошибка', 'новая', 'в работе', 'завершена', 'отменена', 'нет', 'да'])) continue;
                if (preg_match('/^(r?\d{1,2}|кроссовер|легковой|внедорожник|минивэн|пикап|грузовой|crossover|tyre|suv|sedan)$/iu', $valLower)) continue;
                if (preg_match('/^(\+?7|8)?[\d\s\-\(\)]{8,}$/', $valStr)) continue; // Телефон
                if (preg_match('/^\d{12}$/', $valStr)) continue; // ИИН
                if (preg_match('/^\d{2}\.\d{2}\.\d{4}/', $valStr) || preg_match('/^\d{4}-\d{2}-\d{2}/', $valStr)) continue; // Дата
                if (preg_match('/(г\.|ул\.|пр\.|центр|магазин|астана|алматы|караганда|семей|шымкент|усть-каменогорск)/iu', $valLower)) continue; // Центр

                // Если строка состоит из букв имени/фамилии (напр. "Михаил Головин", "Рашина", "Корлан", "Серик Садыков")
                if (preg_match('/^[a-zа-яё\s\.\-]{2,60}$/iu', $valStr) && !preg_match('/^\d+$/', $valStr)) {
                    $clientName = $valStr;
                    break;
                }
            }
        }

        // 2. Фоллбэк: проход по $fields, если что-то не было найдено
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
            elseif (!$carNumber && (strpos($kUpper, 'CAR') !== false || strpos($kUpper, 'GOS') !== false || strpos($kUpper, 'AUTO') !== false)) {
                if (!self::isServiceTypeStr($valStr) && $valStr !== '500') {
                    $carNumber = $valStr;
                }
            }
            elseif (!$centerId && (strpos($kUpper, 'CENTER') !== false || strpos($kUpper, 'SHOP') !== false || strpos($kUpper, 'STORE') !== false || strpos($kUpper, 'POINT') !== false)) {
                $centerId = $valStr;
            }
            elseif (!$timeFirstInterval && (strpos($kUpper, 'TIME_FIRST_INTERVAL') !== false || strpos($kUpper, 'FIRST_INTERVAL') !== false || strpos($kUpper, 'INTERVAL') !== false)) {
                $timeFirstInterval = $valStr;
            }
            elseif (!$dateTime && (strpos($kUpper, 'DATE') !== false || strpos($kUpper, 'TIME') !== false)) {
                $dateTime = $valStr;
            }
            elseif (strpos($kUpper, 'STATUS') !== false || strpos($kUpper, 'STATE') !== false) {
                $status = $valStr;
            }
        }

        if (empty($bodyType)) {
            if (!empty($fields['PROPERTY_CAR_BODY_VALUE'])) {
                $bodyType = is_array($fields['PROPERTY_CAR_BODY_VALUE']) 
                    ? implode(' ', $fields['PROPERTY_CAR_BODY_VALUE']) 
                    : (string)$fields['PROPERTY_CAR_BODY_VALUE'];
            } elseif (!empty($fields['PROPERTY_BODY_TYPE_VALUE'])) {
                $bodyType = is_array($fields['PROPERTY_BODY_TYPE_VALUE']) 
                    ? implode(' ', $fields['PROPERTY_BODY_TYPE_VALUE']) 
                    : (string)$fields['PROPERTY_BODY_TYPE_VALUE'];
            }
        }

        if (empty($timeFirstInterval) && !empty($fields['PROPERTY_TIME_FIRST_INTERVAL_VALUE'])) {
            $timeFirstInterval = is_array($fields['PROPERTY_TIME_FIRST_INTERVAL_VALUE']) 
                ? implode(' ', $fields['PROPERTY_TIME_FIRST_INTERVAL_VALUE']) 
                : (string)$fields['PROPERTY_TIME_FIRST_INTERVAL_VALUE'];
        }

        if (empty($clientName) || in_array(mb_strtolower($clientName), ['ожидание', 'уведомлен', 'уведомлено', 'отправлено', 'доставлено', 'ошибка', 'новая', 'в работе', 'завершена', 'отменена'])) {
            if (!empty($comment) && preg_match('/(?:имя|клиент|фио)[:\s]+([a-zа-яё\s\-]+)/iu', $comment, $mComment)) {
                $clientName = trim($mComment[1]);
            } else {
                $clientName = '—';
            }
        }

        $createdBy = (int)($fields['CREATED_BY'] ?? 0);
        $createdUserName = trim((string)($fields['CREATED_USER_NAME'] ?? ''));

        $isAi = ($createdBy === 8640 || strpos($createdUserName, 'oneit') !== false || strpos($createdUserName, 'Иван Леонов') !== false);

        if ($isAi) {
            $creatorDisplay = 'ИИ Ассистент';
        } elseif ($createdBy > 0) {
            if (!empty($createdUserName)) {
                $creatorDisplay = '[' . $createdBy . '] ' . $createdUserName;
            } else {
                $creatorDisplay = 'Пользователь #' . $createdBy;
            }
        } else {
            $creatorDisplay = 'Сайт';
        }

        return [
            'ID' => $fields['ID'],
            'NAME' => $rawElementName,
            'DATE_CREATE' => $fields['DATE_CREATE'],
            'CREATED_BY' => $createdBy,
            'CREATED_USER_NAME' => $createdUserName,
            'IS_AI' => $isAi,
            'CREATOR_DISPLAY' => $creatorDisplay,
            'CENTER_ID' => $centerId,
            'CENTER_NAME' => $centerName,
            'SERVICE_NAME' => $serviceName,
            'POST_NAME' => $postName,
            'TIME_FIRST_INTERVAL' => trim($timeFirstInterval),
            'DATE_TIME' => trim($timeFirstInterval) ?: ($dateTime ?: $fields['DATE_CREATE']),
            'CLIENT_NAME' => $clientName,
            'CLIENT_PHONE' => $clientPhone,
            'CAR_NUMBER' => self::isServiceTypeStr($carNumber) ? '' : ($carNumber === '500' ? '' : $carNumber),
            'BODY_TYPE' => $bodyType,
            'TIRE_DIAMETER' => $tireDiameter,
            'IS_STORAGE' => $isStorage,
            'STORAGE_CONTRACT' => $storageContract,
            'NOTIFICATION_STATUS' => $notificationStatus,
            'IIN' => $iin,
            'STATUS' => $status,
            'COMMENT' => $comment
        ];
    }

    /**
     * Подготовка массива фильтра для выборки инфоблока
     */
    public static function prepareFilter(array $filter = [])
    {
        $iblockId = self::getBookingIblockId();
        $arFilter = ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y'];

        $hasExplicitDate = false;

        if (isset($filter['DATE_FROM']) || isset($filter['DATE_TO'])) {
            $hasExplicitDate = true;
            if (!empty($filter['DATE_FROM'])) {
                $tsFrom = strtotime($filter['DATE_FROM']);
                if ($tsFrom) {
                    $arFilter['>=DATE_CREATE'] = date('d.m.Y 00:00:00', $tsFrom);
                }
            }
            if (!empty($filter['DATE_TO'])) {
                $tsTo = strtotime($filter['DATE_TO']);
                if ($tsTo) {
                    $arFilter['<=DATE_CREATE'] = date('d.m.Y 23:59:59', $tsTo);
                }
            }
            unset($filter['DATE_FROM'], $filter['DATE_TO']);
        }

        foreach ($filter as $k => $v) {
            if (strpos((string)$k, 'DATE_CREATE') !== false || strpos((string)$k, 'DATE_ACTIVE_FROM') !== false) {
                $hasExplicitDate = true;
            }
            $arFilter[$k] = $v;
        }

        // Если дата в фильтре не выставлена явно и нет отдельного поиска по ID/ALLOW_ALL_DATES, по умолчанию показываем с начала 2026 года
        if (!$hasExplicitDate && empty($filter['ID']) && empty($filter['ALLOW_ALL_DATES'])) {
            $arFilter['>=DATE_CREATE'] = '01.01.2026 00:00:00';
        }
        unset($arFilter['ALLOW_ALL_DATES']);

        return $arFilter;
    }

    /**
     * Высокоскоростной метод получения списка с кешированием и пакетированной выборкой
     */
    public static function getList(array $filter = [], array $order = ['ID' => 'DESC'], $limit = 0)
    {
        if (!Loader::includeModule('iblock')) return [];

        $iblockId = self::getBookingIblockId();
        $arFilter = self::prepareFilter($filter);
        
        // Попытка получить данные из кеша
        $cache = Cache::createInstance();
        $cacheId = 'b_list_v16_' . md5(serialize([$arFilter, $order, $limit, $iblockId]));
        if ($cache->initCache(180, $cacheId, '/aikos_tirebooking/list/')) {
            return $cache->getVars();
        }

        // Формируем массовую выборку элемента со всеми полями PROPERTY_* за 1 SQL запрос
        $res = \CIBlockElement::GetList(
            $order,
            $arFilter,
            false,
            $limit > 0 ? ['nTopCount' => $limit] : false,
            ['ID', 'NAME', 'DATE_CREATE', 'CREATED_BY', 'CREATED_USER_NAME', 'PREVIEW_TEXT', 'DETAIL_TEXT', 'PROPERTY_*']
        );

        $items = [];
        while ($ob = $res->GetNextElement()) {
            $fields = $ob->GetFields();
            $props = $ob->GetProperties();
            
            // Заполняем виртуальные поля PROPERTY_* из свойств для быстрой обработки
            foreach ($props as $pCode => $pData) {
                $fields['PROPERTY_' . $pCode . '_VALUE'] = $pData['VALUE'];
            }
            
            $items[] = self::parseFieldsFast($fields, $props);
        }

        if (empty($items) && $iblockId == 80 && empty($filter)) {
            $arFilter['IBLOCK_ID'] = 94;
            $res = \CIBlockElement::GetList($order, $arFilter, false, $limit > 0 ? ['nTopCount' => $limit] : false, ['ID', 'NAME', 'DATE_CREATE', 'CREATED_BY', 'CREATED_USER_NAME', 'PREVIEW_TEXT', 'DETAIL_TEXT', 'PROPERTY_*']);
            while ($ob = $res->GetNextElement()) {
                $fields = $ob->GetFields();
                $props = $ob->GetProperties();
                foreach ($props as $pCode => $pData) {
                    $fields['PROPERTY_' . $pCode . '_VALUE'] = $pData['VALUE'];
                }
                $items[] = self::parseFieldsFast($fields, $props);
            }
        }

        if ($cache->startDataCache()) {
            $cache->endDataCache($items);
        }

        return $items;
    }
}
