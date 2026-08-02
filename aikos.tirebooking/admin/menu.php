<?php
use Bitrix\Main\Localization\Loc;

if ($APPLICATION->GetGroupRight("aikos.tirebooking") > "D") {
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
            )
        )
    );
    return $aMenu;
}
return false;
