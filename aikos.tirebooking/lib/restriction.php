<?php
namespace Aikos\TireBooking;

use Bitrix\Main\Loader;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;

class RestrictionManager
{
    protected static $tableName = 'b_aikos_tirebooking_restrictions';

    /**
     * Создание таблицы в БД и автомиграция недостающих полей
     */
    public static function checkTableExists()
    {
        $connection = Application::getConnection();
        if (!$connection->isTableExists(self::$tableName)) {
            $sql = "CREATE TABLE IF NOT EXISTS `" . self::$tableName . "` (
                `ID` INT(11) NOT NULL AUTO_INCREMENT,
                `CENTER_ID` INT(11) NOT NULL DEFAULT 0,
                `CENTER_NAME` VARCHAR(255) NULL,
                `DATE_FROM` DATETIME NOT NULL,
                `DATE_TO` DATETIME NOT NULL,
                `REASON` TEXT NULL,
                `MANAGER_ID` INT(11) NOT NULL DEFAULT 0,
                `MANAGER_NAME` VARCHAR(255) NULL,
                `CREATED_AT` DATETIME NOT NULL,
                `DEACTIVATED_ID` INT(11) NULL DEFAULT 0,
                `DEACTIVATED_NAME` VARCHAR(255) NULL,
                `DEACTIVATED_AT` DATETIME NULL,
                `IBLOCK_ELEMENT_ID` INT(11) NULL DEFAULT 0,
                `ACTIVE` CHAR(1) NOT NULL DEFAULT 'Y',
                PRIMARY KEY (`ID`),
                INDEX `IX_CENTER_ACTIVE` (`CENTER_ID`, `ACTIVE`),
                INDEX `IX_DATES` (`DATE_FROM`, `DATE_TO`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

            $connection->executeSqlBatch($sql);
        } else {
            try {
                $fields = $connection->getTableFields(self::$tableName);
                if (!isset($fields['DEACTIVATED_ID'])) {
                    $connection->queryExecute("ALTER TABLE `" . self::$tableName . "` ADD COLUMN `DEACTIVATED_ID` INT(11) NULL DEFAULT 0;");
                }
                if (!isset($fields['DEACTIVATED_NAME'])) {
                    $connection->queryExecute("ALTER TABLE `" . self::$tableName . "` ADD COLUMN `DEACTIVATED_NAME` VARCHAR(255) NULL;");
                }
                if (!isset($fields['DEACTIVATED_AT'])) {
                    $connection->queryExecute("ALTER TABLE `" . self::$tableName . "` ADD COLUMN `DEACTIVATED_AT` DATETIME NULL;");
                }
                if (!isset($fields['IBLOCK_ELEMENT_ID'])) {
                    $connection->queryExecute("ALTER TABLE `" . self::$tableName . "` ADD COLUMN `IBLOCK_ELEMENT_ID` INT(11) NULL DEFAULT 0;");
                }
            } catch (\Exception $e) {
                // Поля уже созданы
            }
        }
    }

    /**
     * Сброс кэша сайта для моментального отключения заблокированных дат
     */
    public static function clearAllCache()
    {
        $cache = Cache::createInstance();
        $cache->cleanDir('/aikos_tirebooking/');
        $cache->cleanDir('/bitrix/html_pages/');
        
        if (function_exists('BXClearCache')) {
            \BXClearCache(true);
        }
    }

    /**
     * Добавление блокировки в штатный Инфоблок #81 (Нерабочие дни) и в журнал аудита
     */
    public static function addRestriction(array $data)
    {
        self::checkTableExists();
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();

        global $USER;
        $managerId = (is_object($USER) && $USER->IsAuthorized()) ? (int)$USER->GetID() : 0;
        $managerName = (is_object($USER) && $USER->IsAuthorized()) ? trim($USER->GetFullName() . ' (' . $USER->GetLogin() . ')') : 'Администратор';

        $centerId = (int)($data['CENTER_ID'] ?? 0);
        $centerName = $data['CENTER_NAME'] ?? '';
        $dateFromStr = trim($data['DATE_FROM'] ?? '');
        $dateToStr = trim($data['DATE_TO'] ?? '');
        $reason = trim($data['REASON'] ?? 'Техническое отключение');

        if (empty($dateFromStr) || empty($dateToStr)) {
            return ['success' => false, 'error' => 'Укажите дату и время начала и окончания блокировки'];
        }

        $dateFromFormatted = date('Y-m-d H:i:s', strtotime($dateFromStr));
        $dateToFormatted = date('Y-m-d H:i:s', strtotime($dateToStr));

        if (strtotime($dateToFormatted) <= strtotime($dateFromFormatted)) {
            return ['success' => false, 'error' => 'Дата окончания должна быть строго позже даты начала'];
        }

        // Создаем запись в штатном Инфоблоке #81 (Нерабочие дни), который использует сайт на странице записи
        $iblockElementId = 0;
        if (Loader::includeModule('iblock')) {
            $el = new \CIBlockElement;
            
            $propValues = [];
            if ($centerId > 0) {
                $propValues['CENTER'] = $centerId;
            }

            $arFields = [
                "IBLOCK_ID" => 81,
                "NAME" => $reason . ' [' . $managerName . ']',
                "ACTIVE" => "Y",
                "ACTIVE_FROM" => \ConvertTimeStamp(strtotime($dateFromFormatted), "FULL"),
                "ACTIVE_TO" => \ConvertTimeStamp(strtotime($dateToFormatted), "FULL"),
                "PROPERTY_VALUES" => $propValues
            ];

            $iblockElementId = (int)$el->Add($arFields);

            // Если есть Инфоблок #95 (onelab_service), добавляем дублирующий элемент для совместимости
            $ib95Res = \CIBlock::GetList([], ['ID' => 95, 'ACTIVE' => 'Y'])->Fetch();
            if ($ib95Res) {
                $el95 = new \CIBlockElement;
                $arFields95 = [
                    "IBLOCK_ID" => 95,
                    "NAME" => $reason . ' [' . $managerName . ']',
                    "ACTIVE" => "Y",
                    "ACTIVE_FROM" => \ConvertTimeStamp(strtotime($dateFromFormatted), "FULL"),
                    "ACTIVE_TO" => \ConvertTimeStamp(strtotime($dateToFormatted), "FULL"),
                    "PROPERTY_VALUES" => $propValues
                ];
                $el95->Add($arFields95);
            }
        }

        // Сохраняем в таблицу аудита модуля
        $sql = "INSERT INTO `" . self::$tableName . "` (
            `CENTER_ID`, `CENTER_NAME`, `DATE_FROM`, `DATE_TO`, `REASON`, `MANAGER_ID`, `MANAGER_NAME`, `CREATED_AT`, `IBLOCK_ELEMENT_ID`, `ACTIVE`
        ) VALUES (
            " . $centerId . ",
            '" . $helper->forSql($centerName) . "',
            '" . $helper->forSql($dateFromFormatted) . "',
            '" . $helper->forSql($dateToFormatted) . "',
            '" . $helper->forSql($reason) . "',
            " . $managerId . ",
            '" . $helper->forSql($managerName) . "',
            '" . date('Y-m-d H:i:s') . "',
            " . $iblockElementId . ",
            'Y'
        )";

        $connection->queryExecute($sql);
        $insertId = $connection->getInsertedId();

        self::clearAllCache();

        return ['success' => true, 'id' => $insertId];
    }

    /**
     * Снятие ограничения с удаления/деактивации элемента из Инфоблока #81
     */
    public static function removeRestriction($id)
    {
        self::checkTableExists();
        $connection = Application::getConnection();
        $helper = $connection->getSqlHelper();

        $id = (int)$id;
        if ($id <= 0) return false;

        global $USER;
        $managerId = (is_object($USER) && $USER->IsAuthorized()) ? (int)$USER->GetID() : 0;
        $managerName = (is_object($USER) && $USER->IsAuthorized()) ? trim($USER->GetFullName() . ' (' . $USER->GetLogin() . ')') : 'Администратор';
        $now = date('Y-m-d H:i:s');

        // Считываем IBLOCK_ELEMENT_ID
        $res = $connection->query("SELECT `IBLOCK_ELEMENT_ID` FROM `" . self::$tableName . "` WHERE `ID` = " . $id);
        if ($row = $res->fetch()) {
            $ibElId = (int)$row['IBLOCK_ELEMENT_ID'];
            if ($ibElId > 0 && Loader::includeModule('iblock')) {
                \CIBlockElement::Delete($ibElId);
            }
        }

        $sql = "UPDATE `" . self::$tableName . "` SET 
                `ACTIVE` = 'N',
                `DEACTIVATED_ID` = {$managerId},
                `DEACTIVATED_NAME` = '" . $helper->forSql($managerName) . "',
                `DEACTIVATED_AT` = '{$now}'
                WHERE `ID` = {$id}";

        $connection->queryExecute($sql);
        self::clearAllCache();
        return true;
    }

    /**
     * Получить список ограничений из журнала
     */
    public static function getList(array $filter = [])
    {
        self::checkTableExists();
        $connection = Application::getConnection();

        $where = ["1=1"];
        if (isset($filter['ACTIVE'])) {
            $where[] = "`ACTIVE` = '" . ($filter['ACTIVE'] == 'Y' ? 'Y' : 'N') . "'";
        }
        if (isset($filter['CENTER_ID']) && (int)$filter['CENTER_ID'] > 0) {
            $where[] = "(`CENTER_ID` = " . (int)$filter['CENTER_ID'] . " OR `CENTER_ID` = 0)";
        }

        $sql = "SELECT * FROM `" . self::$tableName . "` WHERE " . implode(' AND ', $where) . " ORDER BY `ID` DESC";
        $res = $connection->query($sql);

        $items = [];
        while ($row = $res->fetch()) {
            $items[] = $row;
        }

        return $items;
    }

    /**
     * Точная проверка: Заблокирован ли временной слот (например "14:00")
     */
    public static function isSlotBlocked($centerId, $dateStr, $timeStr)
    {
        $fullDateTimeStr = trim($dateStr) . ' ' . trim($timeStr);
        return self::isDateBlocked($centerId, $fullDateTimeStr);
    }

    /**
     * Проверка заблокированной даты с учётом Инфоблока #81 (Нерабочие дни)
     */
    public static function isDateBlocked($centerId, $dateTimeStr)
    {
        self::checkTableExists();
        $connection = Application::getConnection();

        $centerId = (int)$centerId;
        $ts = strtotime($dateTimeStr);
        if (!$ts) return false;

        $dtFormatted = date('Y-m-d H:i:s', $ts);

        // 1. Проверка в нашей таблице аудита
        if (strlen(trim($dateTimeStr)) <= 10) {
            $startDay = date('Y-m-d 00:00:00', $ts);
            $endDay = date('Y-m-d 23:59:59', $ts);

            $sql = "SELECT `ID` FROM `" . self::$tableName . "` 
                    WHERE `ACTIVE` = 'Y' 
                    AND (`CENTER_ID` = {$centerId} OR `CENTER_ID` = 0)
                    AND `DATE_FROM` <= '{$endDay}' 
                    AND `DATE_TO` >= '{$startDay}'
                    LIMIT 1";
        } else {
            $sql = "SELECT `ID` FROM `" . self::$tableName . "` 
                    WHERE `ACTIVE` = 'Y' 
                    AND (`CENTER_ID` = {$centerId} OR `CENTER_ID` = 0)
                    AND `DATE_FROM` <= '{$dtFormatted}' 
                    AND `DATE_TO` >= '{$dtFormatted}'
                    LIMIT 1";
        }

        $res = $connection->query($sql);
        if ($row = $res->fetch()) {
            return true;
        }

        // 2. Проверка в штатном Инфоблоке #81 (Нерабочие дни)
        if (Loader::includeModule('iblock')) {
            $arFilter = [
                'IBLOCK_ID' => [81, 95],
                'ACTIVE' => 'Y',
                '<=ACTIVE_FROM' => \ConvertTimeStamp($ts, "FULL"),
                '>=ACTIVE_TO' => \ConvertTimeStamp($ts, "FULL")
            ];
            if ($centerId > 0) {
                $arFilter['PROPERTY_CENTER'] = [$centerId, false];
            }
            $ibCnt = (int)\CIBlockElement::GetList([], $arFilter, array());
            if ($ibCnt > 0) {
                return true;
            }
        }

        return false;
    }
}
