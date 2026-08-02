<?php
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Loader;
use Aikos\TireBooking\BookingManager;
use Aikos\TireBooking\CenterManager;

$MODULE_ID = "aikos.tirebooking";

if (!Loader::includeModule($MODULE_ID)) {
    echo '<div style="padding: 10px; color: #ef4444;">Модуль "Записи шиномонтажа" (aikos.tirebooking) не установлен.</div>';
    return;
}

$limit = isset($arGadgetParams["ITEMS_COUNT"]) ? (int)$arGadgetParams["ITEMS_COUNT"] : 5;
if ($limit <= 0) $limit = 5;

$showStats = ($arGadgetParams["SHOW_STATS"] ?? "Y") === "Y";

$stats = BookingManager::getStats();
$items = BookingManager::getList([], ['ID' => 'DESC'], $limit);
$centers = CenterManager::getCenters();

// Подключаем стили гаджета
$APPLICATION->SetAdditionalCSS("/bitrix/gadgets/aikos/tire_booking_tracker/style.css");
?>

<div class="bx-gadget-tire-booking">
    <div class="bx-gadget-tb-header">
        <div class="bx-gadget-tb-title">
            <span>🛞</span> Записи шиномонтажа
        </div>
        <div style="font-size: 11px; color: #94a3b8;">
            <?=date('H:i:s')?>
        </div>
    </div>

    <?php if ($showStats): ?>
        <div class="bx-gadget-tb-stats">
            <div class="bx-gadget-tb-stat-card">
                <div class="bx-gadget-tb-stat-num total"><?=$stats['total']?></div>
                <div class="bx-gadget-tb-stat-lbl">Всего</div>
            </div>
            <div class="bx-gadget-tb-stat-card">
                <div class="bx-gadget-tb-stat-num new"><?=$stats['new']?></div>
                <div class="bx-gadget-tb-stat-lbl">Новые</div>
            </div>
            <div class="bx-gadget-tb-stat-card">
                <div class="bx-gadget-tb-stat-num progress"><?=$stats['in_progress']?></div>
                <div class="bx-gadget-tb-stat-lbl">В работе</div>
            </div>
            <div class="bx-gadget-tb-stat-card">
                <div class="bx-gadget-tb-stat-num done"><?=$stats['completed']?></div>
                <div class="bx-gadget-tb-stat-lbl">Готово</div>
            </div>
        </div>
    <?php endif; ?>

    <div class="bx-gadget-tb-list">
        <?php if (empty($items)): ?>
            <div style="text-align: center; padding: 20px; color: #94a3b8; font-size: 12px;">
                Записи на шиномонтаж отсутствуют
            </div>
        <?php else: ?>
            <?php foreach ($items as $item): 
                $statusClass = 'status-new';
                $statusStr = mb_strtolower($item['STATUS']);
                if (strpos($statusStr, 'подтвержд') !== false) $statusClass = 'status-confirmed';
                elseif (strpos($statusStr, 'работ') !== false) $statusClass = 'status-progress';
                elseif (strpos($statusStr, 'заверш') !== false) $statusClass = 'status-completed';
            ?>
                <div class="bx-gadget-tb-item <?=$statusClass?>">
                    <div>
                        <div class="bx-gadget-tb-client">
                            <?=htmlspecialcharsbx($item['CLIENT_NAME'])?>
                        </div>
                        <div class="bx-gadget-tb-info">
                            <span>🕒 <?=htmlspecialcharsbx($item['DATE_TIME'])?></span>
                            <?php if ($item['CAR_NUMBER']): ?>
                                <span class="bx-gadget-tb-car"><?=htmlspecialcharsbx($item['CAR_NUMBER'])?></span>
                            <?php endif; ?>
                            <?php if (!empty($centers[$item['CENTER_ID']])): ?>
                                <span>📍 <?=htmlspecialcharsbx($centers[$item['CENTER_ID']])?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <span class="bx-gadget-tb-badge">
                            <?=htmlspecialcharsbx($item['STATUS'])?>
                        </span>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <div class="bx-gadget-tb-footer">
        <a href="/bitrix/admin/aikos_tirebooking_list.php?lang=<?=LANGUAGE_ID?>" class="bx-gadget-tb-btn">
            Все записи (<?=$stats['total']?>) →
        </a>
    </div>
</div>
