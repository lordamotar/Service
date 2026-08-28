<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php");

use Bitrix\Main\Loader;
use Aikos\TireBooking\CenterManager;
use Aikos\TireBooking\RestrictionManager;

$MODULE_ID = "aikos.tirebooking";
Loader::includeModule($MODULE_ID);
Loader::includeModule("iblock");

$bookingIb = (int)\Bitrix\Main\Config\Option::get($MODULE_ID, "IBLOCK_BOOKING_ID", 80);
$hasIblockAccess = \CIBlock::GetPermission($bookingIb) > "D";
$POST_RIGHT = $APPLICATION->GetGroupRight($MODULE_ID);
if ($POST_RIGHT <= "D" && !$hasIblockAccess) {
    $APPLICATION->AuthForm(GetMessage("ACCESS_DENIED"));
}

$APPLICATION->SetTitle("Настройки и Блокировка записей");

$centers = CenterManager::getCenters();
$message = '';
$messageType = 'ok';

// Обработка действий менеджера
if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add') {
        $centerId = (int)($_POST['CENTER_ID'] ?? 0);
        $centerName = $centerId > 0 ? ($centers[$centerId] ?? 'Центр #' . $centerId) : 'Все шинные центры';
        
        $res = RestrictionManager::addRestriction([
            'CENTER_ID' => $centerId,
            'CENTER_NAME' => $centerName,
            'DATE_FROM' => trim($_POST['DATE_FROM'] ?? ''),
            'DATE_TO' => trim($_POST['DATE_TO'] ?? ''),
            'REASON' => trim($_POST['REASON'] ?? '')
        ]);

        if ($res['success']) {
            $message = 'Ограничение записи успешно установлено! Кэш сайта обновлён.';
            $messageType = 'ok';
        } else {
            $message = 'Ошибка: ' . ($res['error'] ?? 'Не удалось добавить ограничение');
            $messageType = 'error';
        }
    } elseif ($action === 'deactivate') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && RestrictionManager::removeRestriction($id)) {
            $message = 'Ограничение записи успешно снято! Информация о снятии зафиксирована в журнале.';
            $messageType = 'ok';
        }
    }
}

// Загрузка журнала ограничений и аудита
$restrictions = RestrictionManager::getList();

require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");
?>

<style>
.tb-settings-container {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    color: #0f172a;
    background-color: #f8fafc;
    padding: 16px;
    border-radius: 12px;
}

.tb-nav-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 0;
}

.tb-nav-tab {
    padding: 10px 18px;
    font-weight: 600;
    text-decoration: none !important;
    font-size: 14px;
    border-radius: 6px 6px 0 0;
    transition: all 0.2s;
}

.tb-nav-tab.active {
    color: #2563eb;
    border-bottom: 3px solid #2563eb;
    background: #ffffff;
    margin-bottom: -2px;
}

.tb-nav-tab:not(.active) {
    color: #64748b;
    border-bottom: 3px solid transparent;
}

.tb-nav-tab:not(.active):hover {
    color: #1e293b;
    background: #f1f5f9;
}

.tb-card {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 24px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}

.tb-card-title {
    font-size: 16px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 8px;
    border-bottom: 1px solid #f1f5f9;
    padding-bottom: 10px;
}

.tb-form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    align-items: flex-end;
}

.tb-form-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.tb-form-group label {
    font-size: 12px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.tb-input, .tb-select {
    padding: 8px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 13px;
    outline: none;
    background-color: #fff;
}

.tb-input:focus, .tb-select:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
}

.tb-btn-primary {
    background: #2563eb;
    color: #ffffff !important;
    padding: 9px 18px;
    border-radius: 6px;
    border: none;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none !important;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
}
.tb-btn-primary:hover { background: #1d4ed8; }

.tb-btn-secondary {
    background: #f1f5f9;
    color: #475569 !important;
    border: 1px solid #cbd5e1;
    padding: 6px 12px;
    border-radius: 6px;
    border: none;
    font-weight: 600;
    cursor: pointer;
    font-size: 12px;
}
.tb-btn-secondary:hover { background: #e2e8f0; }

.tb-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    text-align: left;
}

.tb-table th {
    background: #f8fafc;
    color: #475569;
    font-weight: 700;
    padding: 10px 14px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 11px;
    text-transform: uppercase;
}

.tb-table td {
    padding: 12px 14px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
}

.tb-status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
}

.badge-active { background: #fee2e2; color: #991b1b; }
.badge-disabled { background: #dcfce7; color: #166534; }

.tb-msg {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    font-weight: 600;
    font-size: 13px;
}
.tb-msg-ok { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
.tb-msg-error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
</style>

<div class="tb-settings-container">
    <!-- Вкладки навигации -->
    <div class="tb-nav-tabs">
        <a href="aikos_tirebooking_list.php?lang=<?=LANGUAGE_ID?>" class="tb-nav-tab">📋 Отслеживание записей</a>
        <a href="aikos_tirebooking_analytics.php?lang=<?=LANGUAGE_ID?>" class="tb-nav-tab">📊 Анализ и Дашборды</a>
        <a href="aikos_tirebooking_settings.php?lang=<?=LANGUAGE_ID?>" class="tb-nav-tab active">⚙️ Настройки и Блокировка записей</a>
    </div>

    <?php if (!empty($message)): ?>
        <div class="tb-msg <?=$messageType === 'ok' ? 'tb-msg-ok' : 'tb-msg-error'?>">
            <?=$messageType === 'ok' ? '✅' : '❌'?> <?=htmlspecialcharsbx($message)?>
        </div>
    <?php endif; ?>

    <!-- Форма установки нового ограничения -->
    <div class="tb-card">
        <div class="tb-card-title">
            <span>🚫 Установить новое ограничение (выключение записи)</span>
        </div>
        <form method="post" action="">
            <?=bitrix_sessid_post();?>
            <input type="hidden" name="action" value="add">

            <div class="tb-form-grid">
                <div class="tb-form-group">
                    <label>Шинный центр / Точка:</label>
                    <select name="CENTER_ID" class="tb-select" required>
                        <option value="0">--- ВСЕ ШИННЫЕ ЦЕНТРЫ ---</option>
                        <?php foreach ($centers as $cId => $cName): ?>
                            <option value="<?=$cId?>"><?=htmlspecialcharsbx($cName)?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="tb-form-group">
                    <label>Отключить С (Дата и Время):</label>
                    <input type="datetime-local" name="DATE_FROM" class="tb-input" required value="<?=date('Y-m-d\TH:i')?>">
                </div>

                <div class="tb-form-group">
                    <label>Отключить ПО (Дата и Время):</label>
                    <input type="datetime-local" name="DATE_TO" class="tb-input" required value="<?=date('Y-m-d\TH:i', strtotime('+1 day'))?>">
                </div>

                <div class="tb-form-group" style="grid-column: span 2;">
                    <label>Причина отключения:</label>
                    <input type="text" name="REASON" class="tb-input" placeholder="Например: Санитарный день, Ремонт оборудования..." required>
                </div>

                <div>
                    <button type="submit" class="tb-btn-primary">🚫 Установить ограничение</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Неизгладимый журнал ограничений и история аудита сотрудников -->
    <div class="tb-card">
        <div class="tb-card-title">
            <span>📜 Журнал ограничений и История действий менеджеров</span>
        </div>

        <table class="tb-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Шинный центр</th>
                    <th>Период отключения (С ... ПО)</th>
                    <th>Причина</th>
                    <th>Кто установил (Менеджер)</th>
                    <th>Кто снял (Менеджер)</th>
                    <th>Статус</th>
                    <th>Действие</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($restrictions)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 30px; color: #94a3b8;">
                            Ограничений не найдено. Запись на все шинные центры работает в обычном режиме.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($restrictions as $item): 
                        $isCurrentlyActive = ($item['ACTIVE'] === 'Y');
                    ?>
                        <tr>
                            <td><strong>#<?=$item['ID']?></strong></td>
                            <td>
                                <strong><?=htmlspecialcharsbx($item['CENTER_NAME'] ?: 'Все центры')?></strong>
                            </td>
                            <td>
                                <div><strong>С:</strong> <?=date('d.m.Y H:i', strtotime($item['DATE_FROM']))?></div>
                                <div><strong>ПО:</strong> <?=date('d.m.Y H:i', strtotime($item['DATE_TO']))?></div>
                            </td>
                            <td><?=htmlspecialcharsbx($item['REASON'])?></td>
                            <td>
                                <div><strong><?=htmlspecialcharsbx($item['MANAGER_NAME'])?></strong></div>
                                <div style="font-size:11px; color:#64748b;"><?=date('d.m.Y H:i', strtotime($item['CREATED_AT']))?></div>
                            </td>
                            <td>
                                <?php if ($item['ACTIVE'] === 'N' && !empty($item['DEACTIVATED_NAME'])): ?>
                                    <div><strong><?=htmlspecialcharsbx($item['DEACTIVATED_NAME'])?></strong></div>
                                    <div style="font-size:11px; color:#64748b;"><?=date('d.m.Y H:i', strtotime($item['DEACTIVATED_AT']))?></div>
                                <?php else: ?>
                                    <span style="color:#94a3b8;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isCurrentlyActive): ?>
                                    <span class="tb-status-badge badge-active">🚫 ЗАБЛОКИРОВАНО</span>
                                <?php else: ?>
                                    <span class="tb-status-badge badge-disabled">🔓 СНЯТО</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isCurrentlyActive): ?>
                                    <form method="post" action="" style="display:inline;" onsubmit="return confirm('Снять ограничение записи? Действие и ваше имя будут зафиксированы в журнале.');">
                                        <?=bitrix_sessid_post();?>
                                        <input type="hidden" name="action" value="deactivate">
                                        <input type="hidden" name="id" value="<?=$item['ID']?>">
                                        <button type="submit" class="tb-btn-secondary">🔓 Снять ограничение</button>
                                    </form>
                                <?php else: ?>
                                    <span style="font-size:11px; color:#64748b;">Завершено</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php");
