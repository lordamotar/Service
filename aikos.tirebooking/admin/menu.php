<?php
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

global $APPLICATION, $USER;
if (!is_object($USER) || !$USER->IsAuthorized()) {
    return false;
}

// Возвращаем подпункт внутри единого дерева menu_callcenter_aikos (не создавая дублирующий корень)
return [
    "parent_menu" => "menu_callcenter_aikos",
    "sort"        => 140, // Сортировка внутри папки (100: Продажи, 110: Заказы, 120: Дашборды, 130: Веб-формы, 140: Шиномонтаж)
    "text"        => "Шиномонтаж",
    "title"       => "Записи на шиномонтаж",
    "url"         => "aikos_tirebooking_list.php?lang=" . LANGUAGE_ID,
    "more_url"    => [
        "aikos_tirebooking_list.php",
        "aikos_tirebooking_analytics.php",
        "aikos_tirebooking_settings.php"
    ],
    "icon"        => "default_menu_icon",
    "page_icon"   => "default_page_icon",
    "items_id"    => "menu_callcenter_shinomontazh",
];
