<?php
use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses(
    "aikos.tirebooking",
    array(
        "Aikos\\TireBooking\\BookingTable" => "lib/booking.php",
        "Aikos\\TireBooking\\CenterTable" => "lib/center.php",
        "Aikos\\TireBooking\\BookingManager" => "lib/booking.php",
        "Aikos\\TireBooking\\CenterManager" => "lib/center.php",
        "Aikos\\TireBooking\\AnalyticsManager" => "lib/analytics.php",
        "Aikos\\TireBooking\\RestrictionManager" => "lib/restriction.php",
    )
);
