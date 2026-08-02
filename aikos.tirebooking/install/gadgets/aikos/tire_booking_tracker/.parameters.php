<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

$arParameters = array(
    "PARAMETERS" => array(
        "ITEMS_COUNT" => array(
            "NAME" => "Количество выводимых записей",
            "TYPE" => "STRING",
            "DEFAULT" => "5"
        ),
        "REFRESH_INTERVAL" => array(
            "NAME" => "Интервал автообновления (сек)",
            "TYPE" => "STRING",
            "DEFAULT" => "60"
        ),
        "SHOW_STATS" => array(
            "NAME" => "Показывать карточки статистики",
            "TYPE" => "CHECKBOX",
            "DEFAULT" => "Y"
        )
    )
);
