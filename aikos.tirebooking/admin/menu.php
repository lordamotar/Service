<?php
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

$bookingIb = (int)Option::get("aikos.tirebooking", "IBLOCK_BOOKING_ID", 80);
$hasIblockAccess = Loader::includeModule("iblock") && (\CIBlock::GetPermission($bookingIb) > "D");
$hasModuleAccess = $APPLICATION->GetGroupRight("aikos.tirebooking") > "D";

if ($hasModuleAccess || $hasIblockAccess) {
    $aMenu = array(
        "parent_menu" => "global_menu_services",
        "section" => "aikos_tirebooking",
        "sort" => 350,
        "text" => "Записи шиномонтажа",
        "title" => "Мониторинг и отслеживание записей шиномонтажа",
        "icon" => "service_menu_icon",
        "page_icon" => "service_page_icon",
        "items_id" => "menu_aikos_tirebooking",
        "items" => array(
            array(
                "text" => "Отслеживание записей",
                "url" => "aikos_tirebooking_list.php?lang=" . LANGUAGE_ID,
                "more_url" => array("aikos_tirebooking_list.php"),
                "title" => "Список и мониторинг записей шиномонтажа",
            ),
            array(
                "text" => "Анализ и Дашборды",
                "url" => "aikos_tirebooking_analytics.php?lang=" . LANGUAGE_ID,
                "more_url" => array("aikos_tirebooking_analytics.php"),
                "title" => "Аналитика, дашборды и наглядные графики",
            ),
            array(
                "text" => "Настройки и Блокировки",
                "url" => "aikos_tirebooking_settings.php?lang=" . LANGUAGE_ID,
                "more_url" => array("aikos_tirebooking_settings.php"),
                "title" => "Отключение записи на шиномонтаж и журнал действий менеджеров",
            )
        )
    );
    return $aMenu;
}
return false;
