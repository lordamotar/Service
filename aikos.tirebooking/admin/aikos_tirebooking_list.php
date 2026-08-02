<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php");

use Bitrix\Main\Loader;
use Aikos\TireBooking\BookingManager;
use Aikos\TireBooking\CenterManager;

$MODULE_ID = "aikos.tirebooking";
Loader::includeModule($MODULE_ID);
Loader::includeModule("iblock");

$POST_RIGHT = $APPLICATION->GetGroupRight($MODULE_ID);
if ($POST_RIGHT <= "D") {
    $APPLICATION->AuthForm(GetMessage("ACCESS_DENIED"));
}

$APPLICATION->SetTitle("Отслеживание и мониторинг записей шиномонтажа");

require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");

$centerId = isset($_REQUEST['CENTER_ID']) ? (int)$_REQUEST['CENTER_ID'] : 0;
$centers = CenterManager::getCenters();
$stats = BookingManager::getStats(null, null, $centerId);
$items = BookingManager::getList($centerId ? ['PROPERTY_CENTER_ID' => $centerId] : [], ['ID' => 'DESC'], 100);

?>
<div class="adm-toolbar-panel">
    <form method="get" action="" style="display: flex; gap: 15px; align-items: center; margin-bottom: 20px;">
        <label><b>Фильтр по шинному центру:</b></label>
        <select name="CENTER_ID" onchange="this.form.submit()">
            <option value="0">--- Все шинные центры ---</option>
            <?php foreach ($centers as $id => $name): ?>
                <option value="<?=$id?>" <?=$id == $centerId ? 'selected' : ''?>><?=htmlspecialcharsbx($name)?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; margin-bottom: 25px;">
    <div style="background: #e0f2fe; border: 1px solid #38bdf8; border-radius: 8px; padding: 15px; text-align: center;">
        <div style="font-size: 24px; font-weight: bold; color: #0369a1;"><?=$stats['total']?></div>
        <div style="color: #0c4a6e; font-size: 13px;">Всего записей</div>
    </div>
    <div style="background: #fef9c3; border: 1px solid #facc15; border-radius: 8px; padding: 15px; text-align: center;">
        <div style="font-size: 24px; font-weight: bold; color: #a16207;"><?=$stats['new']?></div>
        <div style="color: #713f12; font-size: 13px;">Новые</div>
    </div>
    <div style="background: #dcfce7; border: 1px solid #4ade80; border-radius: 8px; padding: 15px; text-align: center;">
        <div style="font-size: 24px; font-weight: bold; color: #15803d;"><?=$stats['confirmed']?></div>
        <div style="color: #14532d; font-size: 13px;">Подтверждённые</div>
    </div>
    <div style="background: #e0e7ff; border: 1px solid #818cf8; border-radius: 8px; padding: 15px; text-align: center;">
        <div style="font-size: 24px; font-weight: bold; color: #4338ca;"><?=$stats['in_progress']?></div>
        <div style="color: #1e1b4b; font-size: 13px;">В работе</div>
    </div>
    <div style="background: #f3e8ff; border: 1px solid #c084fc; border-radius: 8px; padding: 15px; text-align: center;">
        <div style="font-size: 24px; font-weight: bold; color: #7e22ce;"><?=$stats['completed']?></div>
        <div style="color: #581c87; font-size: 13px;">Завершено</div>
    </div>
</div>

<table class="adm-list-table">
    <thead>
        <tr class="adm-list-table-header">
            <th class="adm-list-table-cell">ID</th>
            <th class="adm-list-table-cell">Клиент / Запись</th>
            <th class="adm-list-table-cell">Телефон</th>
            <th class="adm-list-table-cell">Гос. номер</th>
            <th class="adm-list-table-cell">Дата и время</th>
            <th class="adm-list-table-cell">Статус</th>
            <th class="adm-list-table-cell">Шинный центр</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($items)): ?>
            <tr class="adm-list-table-row">
                <td colspan="7" class="adm-list-table-cell" style="text-align: center; color: #888;">Записи не найдены</td>
            </tr>
        <?php else: ?>
            <?php foreach ($items as $item): ?>
                <tr class="adm-list-table-row">
                    <td class="adm-list-table-cell"><?=$item['ID']?></td>
                    <td class="adm-list-table-cell"><b><?=htmlspecialcharsbx($item['CLIENT_NAME'])?></b></td>
                    <td class="adm-list-table-cell"><?=htmlspecialcharsbx($item['CLIENT_PHONE'])?></td>
                    <td class="adm-list-table-cell"><code><?=htmlspecialcharsbx($item['CAR_NUMBER'])?></code></td>
                    <td class="adm-list-table-cell"><?=htmlspecialcharsbx($item['DATE_TIME'])?></td>
                    <td class="adm-list-table-cell">
                        <span style="display:inline-block; padding: 4px 8px; border-radius: 4px; background: #e2e8f0; font-size: 12px;">
                            <?=htmlspecialcharsbx($item['STATUS'])?>
                        </span>
                    </td>
                    <td class="adm-list-table-cell">
                        <?=$centers[$item['CENTER_ID']] ?? ($item['CENTER_ID'] ? 'Центр #'.$item['CENTER_ID'] : '-')?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php");
