<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php");

use Bitrix\Main\Loader;
use Aikos\TireBooking\BookingManager;
use Aikos\TireBooking\CenterManager;
use Aikos\TireBooking\AnalyticsManager;

$MODULE_ID = "aikos.tirebooking";
Loader::includeModule($MODULE_ID);
Loader::includeModule("iblock");

$POST_RIGHT = $APPLICATION->GetGroupRight($MODULE_ID);
if ($POST_RIGHT <= "D") {
    $APPLICATION->AuthForm(GetMessage("ACCESS_DENIED"));
}

$APPLICATION->SetTitle("Анализ и Дашборды - Шиномонтаж");

// Параметры фильтрации
$dateFrom = trim($_REQUEST['DATE_FROM'] ?? '');
$dateTo = trim($_REQUEST['DATE_TO'] ?? '');
$centerId = isset($_REQUEST['CENTER_ID']) ? (int)$_REQUEST['CENTER_ID'] : 0;
$clientType = trim($_REQUEST['CLIENT_TYPE'] ?? 'all');
$tireDiameter = trim($_REQUEST['TIRE_DIAMETER'] ?? 'all');
$storageOnly = trim($_REQUEST['STORAGE_ONLY'] ?? 'all');
$activeScreen = isset($_REQUEST['SCREEN']) ? max(1, min(2, (int)$_REQUEST['SCREEN'])) : 1;

$centers = CenterManager::getCenters();

// Загрузка динамических аналитических данных
$analytics = AnalyticsManager::getAnalyticsData([
    'CENTER_ID' => $centerId,
    'CLIENT_TYPE' => $clientType,
    'DATE_FROM' => $dateFrom,
    'DATE_TO' => $dateTo,
    'TIRE_DIAMETER' => $tireDiameter,
    'STORAGE_ONLY' => $storageOnly
]);

$b1 = $analytics['block1'];
$b2 = $analytics['block2'];

require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");
?>

<!-- Chart.js для отрисовки графиков -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
.tb-analytics-container {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    color: #0f172a;
    background-color: #f8fafc;
    padding: 12px 16px;
    border-radius: 12px;
}

.tb-nav-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 12px;
    border-bottom: 2px solid #e2e8f0;
    padding-bottom: 0;
}

.tb-nav-tab {
    padding: 8px 16px;
    font-weight: 600;
    text-decoration: none !important;
    font-size: 13px;
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

/* Переключатель Экранов без прокрутки */
.tb-screen-switcher {
    display: flex;
    gap: 8px;
    margin-bottom: 12px;
}

.tb-screen-btn {
    padding: 8px 16px;
    font-size: 13px;
    font-weight: 700;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #475569;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.tb-screen-btn.active {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
    box-shadow: 0 2px 4px rgba(37,99,235,0.2);
}

.tb-screen-btn:hover:not(.active) {
    background: #e2e8f0;
    color: #0f172a;
}

.tb-slice-panel {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 10px 14px;
    margin-bottom: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
}

.tb-slice-row {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: flex-end;
}

.tb-slice-group {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.tb-slice-group label {
    font-size: 11px;
    font-weight: 700;
    color: #475569;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.tb-slice-input, .tb-slice-select {
    padding: 6px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 12px;
    outline: none;
    background-color: #fff;
}

.tb-slice-input:focus, .tb-slice-select:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 2px rgba(37,99,235,0.12);
}

.tb-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    margin-bottom: 12px;
}

@media (max-width: 900px) {
    .tb-kpi-grid { grid-template-columns: repeat(2, 1fr); }
}

.tb-kpi-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 10px;
    text-align: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    position: relative;
    overflow: hidden;
}

.tb-kpi-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
}

.kpi-blue::before { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
.kpi-green::before { background: linear-gradient(90deg, #10b981, #34d399); }
.kpi-purple::before { background: linear-gradient(90deg, #8b5cf6, #a78bfa); }
.kpi-orange::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }

.tb-kpi-title {
    font-size: 11px;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    margin-bottom: 4px;
}

.tb-kpi-value {
    font-size: 22px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.1;
}

/* Компактный грид для 1 экрана */
.tb-dashboard-grid-compact {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin-bottom: 12px;
}

@media (max-width: 1100px) {
    .tb-dashboard-grid-compact { grid-template-columns: 1fr; }
}

.tb-widget-card {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    display: flex;
    flex-direction: column;
}

.tb-widget-fullwidth {
    grid-column: 1 / -1;
}

.tb-widget-title {
    font-size: 13px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 8px;
    padding-bottom: 6px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.tb-widget-body {
    flex: 1;
    min-height: 190px;
    position: relative;
}

/* Стили для Тепловой Карты */
.tb-heatmap-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
    text-align: center;
}

.tb-heatmap-table th {
    background: #f1f5f9;
    color: #475569;
    padding: 4px;
    border: 1px solid #cbd5e1;
    font-weight: 700;
}

.tb-heatmap-table td {
    padding: 4px 2px;
    border: 1px solid #e2e8f0;
    font-size: 10px;
    font-weight: 600;
}

.tb-heatmap-table td.cell-hour {
    background: #f8fafc;
    font-weight: 700;
    color: #334155;
}

/* Treemap */
.tb-treemap-grid {
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    grid-template-rows: 1fr 1fr;
    gap: 6px;
    height: 180px;
}

.tb-treemap-item {
    border-radius: 6px;
    padding: 10px;
    color: #ffffff;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}

.tb-treemap-name { font-size: 13px; font-weight: 700; }
.tb-treemap-val { font-size: 18px; font-weight: 800; margin-top: 2px; }

/* Востребованность шин */
.tb-bar-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 4px;
}

.tb-bar-item {
    display: flex;
    align-items: center;
    gap: 8px;
}

.tb-bar-label { width: 40px; font-weight: 700; font-size: 12px; color: #334155; }
.tb-bar-track { flex: 1; height: 18px; background: #f1f5f9; border-radius: 4px; overflow: hidden; }
.tb-bar-fill { height: 100%; background: linear-gradient(90deg, #2563eb, #3b82f6); border-radius: 4px; }
.tb-bar-value { width: 110px; font-size: 11px; font-weight: 600; color: #475569; text-align: right; }

.tb-btn-primary {
    background: #2563eb;
    color: #ffffff !important;
    padding: 6px 12px;
    border-radius: 6px;
    border: none;
    font-weight: 600;
    cursor: pointer;
    font-size: 12px;
}
.tb-btn-primary:hover { background: #1d4ed8; }

.tb-btn-secondary {
    background: #ffffff;
    color: #475569 !important;
    border: 1px solid #cbd5e1;
    padding: 6px 12px;
    border-radius: 6px;
    font-weight: 600;
    font-size: 12px;
    text-decoration: none !important;
}
.tb-btn-secondary:hover { background: #f1f5f9; }
</style>

<div class="tb-analytics-container">
    <!-- Вкладки навигации -->
    <div class="tb-nav-tabs">
        <a href="aikos_tirebooking_list.php?lang=<?=LANGUAGE_ID?>" class="tb-nav-tab">📋 Отслеживание записей</a>
        <a href="aikos_tirebooking_analytics.php?lang=<?=LANGUAGE_ID?>" class="tb-nav-tab active">📊 Анализ и Дашборды</a>
    </div>

    <!-- ЕДИНАЯ ПАНЕЛЬ ФИЛЬТРОВ-СРЕЗОВ -->
    <div class="tb-slice-panel">
        <form method="get" action="" id="analyticsFilterForm" class="tb-slice-row">
            <input type="hidden" name="SCREEN" id="inputScreen" value="<?=$activeScreen?>">

            <div class="tb-slice-group">
                <label>📅 Срез: ДАТА (Период)</label>
                <div style="display:flex; gap:4px;">
                    <input type="date" name="DATE_FROM" value="<?=htmlspecialcharsbx($dateFrom)?>" class="tb-slice-input">
                    <input type="date" name="DATE_TO" value="<?=htmlspecialcharsbx($dateTo)?>" class="tb-slice-input">
                </div>
            </div>

            <div class="tb-slice-group">
                <label>🏢 Срез: ЦЕНТР (Город/Филиал)</label>
                <select name="CENTER_ID" class="tb-slice-select" style="min-width: 180px;">
                    <option value="0">--- Все центры ---</option>
                    <?php foreach ($centers as $cId => $cName): ?>
                        <option value="<?=$cId?>" <?=$cId == $centerId ? 'selected' : ''?>><?=htmlspecialcharsbx($cName)?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="tb-slice-group">
                <label>👥 Срез: ТИП КЛИЕНТА</label>
                <select name="CLIENT_TYPE" class="tb-slice-select">
                    <option value="all" <?=$clientType == 'all' ? 'selected' : ''?>>Все клиенты</option>
                    <option value="new" <?=$clientType == 'new' ? 'selected' : ''?>>Новый клиент (1 запись)</option>
                    <option value="regular" <?=$clientType == 'regular' ? 'selected' : ''?>>Постоянный клиент (>1 записи)</option>
                </select>
            </div>

            <div class="tb-slice-group">
                <label>🛞 Срез: ДИАМЕТР ШИН</label>
                <select name="TIRE_DIAMETER" class="tb-slice-select">
                    <option value="all" <?=$tireDiameter == 'all' ? 'selected' : ''?>>Все диаметры (R15-R19+)</option>
                    <option value="R15" <?=$tireDiameter == 'R15' ? 'selected' : ''?>>R15</option>
                    <option value="R16" <?=$tireDiameter == 'R16' ? 'selected' : ''?>>R16</option>
                    <option value="R17" <?=$tireDiameter == 'R17' ? 'selected' : ''?>>R17</option>
                    <option value="R18" <?=$tireDiameter == 'R18' ? 'selected' : ''?>>R18</option>
                    <option value="R19" <?=$tireDiameter == 'R19' ? 'selected' : ''?>>R19+</option>
                    <option value="NONE" <?=$tireDiameter == 'NONE' ? 'selected' : ''?>>Не заполнено</option>
                </select>
            </div>

            <div class="tb-slice-group">
                <label>📦 Срез: ХРАНЕНИЕ</label>
                <select name="STORAGE_ONLY" id="storageFilterSelect" class="tb-slice-select">
                    <option value="all" <?=$storageOnly == 'all' ? 'selected' : ''?>>Все клиенты</option>
                    <option value="Y" <?=$storageOnly == 'Y' ? 'selected' : ''?>>Только на хранении</option>
                    <option value="N" <?=$storageOnly == 'N' ? 'selected' : ''?>>Без хранения</option>
                </select>
            </div>

            <div style="display:flex; gap:6px;">
                <button type="submit" class="tb-btn-primary">🔍 Применить срез</button>
                <a href="aikos_tirebooking_analytics.php?lang=<?=LANGUAGE_ID?>" class="tb-btn-secondary">✖ Сбросить</a>
            </div>
        </form>
    </div>

    <!-- МГНОВЕННЫЙ ПЕРЕКЛЮЧАТЕЛЬ ЭКРАНОВ БЕЗ ПРОКРУТКИ -->
    <div class="tb-screen-switcher">
        <button type="button" class="tb-screen-btn <?=$activeScreen == 1 ? 'active' : ''?>" id="btn-screen-1" onclick="switchScreen(1)">
            📊 Экран 1: Загрузка по часам, Динамика и Нагрузка по центрам
        </button>
        <button type="button" class="tb-screen-btn <?=$activeScreen == 2 ? 'active' : ''?>" id="btn-screen-2" onclick="switchScreen(2)">
            🚙 Экран 2: Клиентская база, Хранение, Кузова и Шины
        </button>
    </div>

    <!-- ════════════════════════════════════════════════════════════════════════════════ -->
    <!-- ЭКРАН 1: ЗАГРУЗКА И НАГРУЗКА ПО ТОРГОВЫМ ТОЧКАМ (БЕЗ ПРОКРУТКИ)                -->
    <!-- ════════════════════════════════════════════════════════════════════════════════ -->
    <div id="screen-container-1" class="tb-screen-view" style="display: <?=$activeScreen == 1 ? 'block' : 'none'?>;">
        <!-- KPI 1 -->
        <div class="tb-kpi-grid">
            <div class="tb-kpi-card kpi-blue">
                <div class="tb-kpi-title">ВСЕГО ЗАПИСЕЙ</div>
                <div class="tb-kpi-value"><?=number_format($b1['total_records'], 0, '', ' ')?></div>
            </div>
            <div class="tb-kpi-card kpi-green">
                <div class="tb-kpi-title">% НА ХРАНЕНИИ</div>
                <div class="tb-kpi-value"><?=$b1['storage_percent']?>%</div>
            </div>
            <div class="tb-kpi-card kpi-purple">
                <div class="tb-kpi-title">% С УВЕДОМЛЕНИЕМ</div>
                <div class="tb-kpi-value"><?=$b1['notified_percent']?>%</div>
            </div>
            <div class="tb-kpi-card kpi-orange">
                <div class="tb-kpi-title">ЗАГРУЖЕННЫЙ ДЕНЬ</div>
                <div class="tb-kpi-value"><?=$b1['busiest_day']?></div>
            </div>
        </div>

        <!-- 3 Виджета в ряд -->
        <div class="tb-dashboard-grid-compact">
            <!-- 1.1 ТЕПЛОВАЯ КАРТА -->
            <div class="tb-widget-card">
                <div class="tb-widget-title">
                    <span>🔥 [ ТЕПЛОВАЯ КАРТА ПО ЧАСАМ ]</span>
                </div>
                <div class="tb-widget-body" style="overflow-x: auto;">
                    <table class="tb-heatmap-table">
                        <thead>
                            <tr>
                                <th>Час</th><th>Пн</th><th>Вт</th><th>Ср</th><th>Чт</th><th>Пт</th><th>Сб</th><th>Вс</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $hoursToShow = [8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20];
                            $maxCellVal = 1;
                            foreach ($hoursToShow as $h) {
                                foreach ([1, 2, 3, 4, 5, 6, 0] as $dIdx) {
                                    $v = $b1['heatmap'][$h][$dIdx] ?? 0;
                                    if ($v > $maxCellVal) $maxCellVal = $v;
                                }
                            }
                            foreach ($hoursToShow as $h): 
                                $row = $b1['heatmap'][$h] ?? [1=>0,2=>0,3=>0,4=>0,5=>0,6=>0,0=>0];
                            ?>
                                <tr>
                                    <td class="cell-hour"><?=$h?>:00</td>
                                    <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $dIdx):
                                        $val = $row[$dIdx] ?? 0;
                                        $bgAlpha = $val > 0 ? min(0.95, max(0.1, $val / $maxCellVal)) : 0;
                                        $bgColor = $val > 0 ? "rgba(37, 99, 235, {$bgAlpha})" : "transparent";
                                        $textColor = ($bgAlpha > 0.45) ? '#ffffff' : '#0f172a';
                                    ?>
                                        <td style="background-color: <?=$bgColor?>; color: <?=$textColor?>;">
                                            <?=number_format($val, 0, '', ' ')?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 1.3 ДИНАМИКА ЗАПИСЕЙ ПО ДНЯМ -->
            <div class="tb-widget-card">
                <div class="tb-widget-title">
                    <span>📉 [ ДИНАМИКА ЗАПИСЕЙ ПО ДНЯМ ]</span>
                    <span style="font-size:11px; font-weight: normal; color:#64748b;">Среднее (<?=$b1['trend']['avg']?>/день)</span>
                </div>
                <div class="tb-widget-body">
                    <canvas id="chartDynamics" style="max-height: 200px;"></canvas>
                </div>
            </div>

            <!-- 1.4 ГЛУБИНА БРОНИРОВАНИЯ -->
            <div class="tb-widget-card">
                <div class="tb-widget-title">
                    <span>⏳ [ ГЛУБИНА БРОНИРОВАНИЯ ]</span>
                </div>
                <div class="tb-widget-body">
                    <canvas id="chartBookingDepth" style="max-height: 200px;"></canvas>
                </div>
            </div>

            <!-- 1.2 НАГРУЗКА ПО ЦЕНТРАМ (НА ВЕСЬ ЭКРАН С ВЕРТИКАЛЬНОЙ ГИСТОГРАММОЙ) -->
            <div class="tb-widget-card tb-widget-fullwidth" style="margin-top: 4px;">
                <div class="tb-widget-title">
                    <span>🏢 [ НАГРУЗКА ПО ЦЕНТРАМ И ТОРГОВЫМ ТОЧКАМ (ВСЕ ЦЕНТРЫ) ]</span>
                    <span style="font-size:11px; font-weight: normal; color:#64748b;">Вертикальная гистограмма посещаемости</span>
                </div>
                <div class="tb-widget-body">
                    <canvas id="chartCenterLoad" style="max-height: 220px; width: 100%;"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════════════════════════════ -->
    <!-- ЭКРАН 2: КЛИЕНТЫ, ХРАНЕНИЕ И ШИНЫ (БЕЗ ПРОКРУТКИ)                              -->
    <!-- ════════════════════════════════════════════════════════════════════════════════ -->
    <div id="screen-container-2" class="tb-screen-view" style="display: <?=$activeScreen == 2 ? 'block' : 'none'?>;">
        <!-- KPI 2 -->
        <div class="tb-kpi-grid">
            <div class="tb-kpi-card kpi-blue">
                <div class="tb-kpi-title">ВСЕГО КЛИЕНТОВ</div>
                <div class="tb-kpi-value"><?=number_format($b2['total_clients'], 0, '', ' ')?></div>
            </div>
            <div class="tb-kpi-card kpi-green">
                <div class="tb-kpi-title">ДОГОВОРОВ ХРАНЕНИЯ</div>
                <div class="tb-kpi-value"><?=number_format($b2['storage_contracts'], 0, '', ' ')?></div>
            </div>
            <div class="tb-kpi-card kpi-purple">
                <div class="tb-kpi-title">% НА ХРАНЕНИИ</div>
                <div class="tb-kpi-value"><?=$b2['storage_percent']?>%</div>
            </div>
            <div class="tb-kpi-card kpi-orange">
                <div class="tb-kpi-title">ТОП РАЗМЕР ШИН</div>
                <div class="tb-kpi-value"><?=$b2['top_tire_size']?></div>
            </div>
        </div>

        <div class="tb-dashboard-grid-compact" style="grid-template-columns: repeat(2, 1fr);">
            <!-- 2.1 ДОЛЯ КЛИЕНТОВ НА ХРАНЕНИИ -->
            <div class="tb-widget-card">
                <div class="tb-widget-title">
                    <span>🍩 [ ДОЛЯ КЛИЕНТОВ НА ХРАНЕНИИ ]</span>
                    <span style="font-size:11px; font-weight: normal; color:#2563eb; cursor:pointer;" onclick="filterByStorage()">* Клик по "Да" фильтрует экран</span>
                </div>
                <div class="tb-widget-body" style="display:flex; justify-content:center; align-items:center;">
                    <canvas id="chartStorageDoughnut" style="max-height: 210px;"></canvas>
                </div>
            </div>

            <!-- 2.2 ТИП КУЗОВА И ДИАМЕТРЫ ШИН (Stacked Column Chart) -->
            <div class="tb-widget-card">
                <div class="tb-widget-title">
                    <span>🚙 [ ТИП КУЗОВА И ДИАМЕТРЫ ШИН ]</span>
                    <span style="font-size:11px; font-weight: normal; color:#64748b;">Гистограмма с накоплением</span>
                </div>
                <div class="tb-widget-body">
                    <canvas id="chartStackedBrands" style="max-height: 210px;"></canvas>
                </div>
            </div>

            <!-- 2.3 СТРУКТУРА КУЗОВОВ АВТО -->
            <div class="tb-widget-card">
                <div class="tb-widget-title">
                    <span>🚘 [ СТРУКТУРА КУЗОВОВ АВТО ]</span>
                </div>
                <div class="tb-widget-body">
                    <div class="tb-treemap-grid">
                        <div class="tb-treemap-item" style="background: linear-gradient(135deg, #1e40af, #3b82f6); grid-column: span 1; grid-row: span 2;">
                            <div class="tb-treemap-name">Седан</div>
                            <div class="tb-treemap-val"><?=$b2['body_types']['Седан'] ?? 0?>%</div>
                        </div>
                        <div class="tb-treemap-item" style="background: linear-gradient(135deg, #0d9488, #14b8a6);">
                            <div class="tb-treemap-name">Кроссовер</div>
                            <div class="tb-treemap-val"><?=$b2['body_types']['Кроссовер'] ?? 0?>%</div>
                        </div>
                        <div class="tb-treemap-item" style="background: linear-gradient(135deg, #64748b, #475569); display:flex; flex-direction:row; justify-content:space-around;">
                            <div>
                                <div class="tb-treemap-name" style="font-size:12px;">Внедорожник</div>
                                <div class="tb-treemap-val" style="font-size:15px;"><?=$b2['body_types']['Внедорожник'] ?? 0?>%</div>
                            </div>
                            <div>
                                <div class="tb-treemap-name" style="font-size:12px; color:#cbd5e1;">Не заполнено</div>
                                <div class="tb-treemap-val" style="font-size:15px; color:#ffffff;"><?=$b2['body_types']['Не заполнено'] ?? 0?>%</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 2.4 ВОСТРЕБОВАННОСТЬ ДИАМЕТРОВ ШИН -->
            <div class="tb-widget-card">
                <div class="tb-widget-title">
                    <span>🛞 [ ВОСТРЕБОВАННОСТЬ ДИАМЕТРОВ ШИН ]</span>
                </div>
                <div class="tb-widget-body">
                    <div class="tb-bar-list">
                        <?php foreach ($b2['tire_diameters'] as $dName => $dInfo): ?>
                            <div class="tb-bar-item">
                                <div class="tb-bar-label"><?=$dName?></div>
                                <div class="tb-bar-track">
                                    <div class="tb-bar-fill" style="width: <?=$dInfo['percent']?>%;"></div>
                                </div>
                                <div class="tb-bar-value"><?=$dInfo['percent']?>% (<?=number_format($dInfo['count'], 0, '', ' ')?>)</div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ИНИЦИАЛИЗАЦИЯ И УПРАВЛЕНИЕ ГРАФИКАМИ CHART.JS -->
<script>
function switchScreen(screenNum) {
    document.getElementById('screen-container-1').style.display = (screenNum === 1) ? 'block' : 'none';
    document.getElementById('screen-container-2').style.display = (screenNum === 2) ? 'block' : 'none';
    
    document.getElementById('btn-screen-1').classList.toggle('active', screenNum === 1);
    document.getElementById('btn-screen-2').classList.toggle('active', screenNum === 2);
    
    document.getElementById('inputScreen').value = screenNum;
}

document.addEventListener('DOMContentLoaded', function() {
    if (typeof Chart === 'undefined') return;

    // 1.2 НАГРУЗКА ПО ЦЕНТРАМ (ВЕРТИКАЛЬНАЯ ГИСТОГРАММА НА ВЕСЬ ЭКРАН)
    var ctxCenter = document.getElementById('chartCenterLoad').getContext('2d');
    var centersData = <?=json_encode($b1['centers_load'], JSON_UNESCAPED_UNICODE)?>;
    var centerLabels = Object.keys(centersData);
    var centerValues = Object.values(centersData);

    new Chart(ctxCenter, {
        type: 'bar',
        data: {
            labels: centerLabels,
            datasets: [{
                label: 'Записей',
                data: centerValues,
                backgroundColor: '#2563eb',
                hoverBackgroundColor: '#1d4ed8',
                borderRadius: 4,
                barPercentage: 0.7
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    ticks: {
                        font: { size: 11, weight: '600' },
                        maxRotation: 45,
                        minRotation: 30,
                        autoSkip: false
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: { font: { size: 10 } }
                }
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return 'Записей: ' + context.parsed.y.toLocaleString();
                        }
                    }
                }
            }
        }
    });

    // 1.3 ДИНАМИКА ЗАПИСЕЙ ПО ДНЯМ
    var ctxDyn = document.getElementById('chartDynamics').getContext('2d');
    var trendData = <?=json_encode($b1['trend'], JSON_UNESCAPED_UNICODE)?>;
    new Chart(ctxDyn, {
        type: 'line',
        data: {
            labels: trendData.days,
            datasets: [
                {
                    label: 'Записи',
                    data: trendData.values,
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37, 99, 235, 0.12)',
                    fill: true,
                    tension: 0.3,
                    borderWidth: 2,
                    pointRadius: 3
                },
                {
                    label: 'Среднее (' + trendData.avg + ')',
                    data: Array(trendData.days.length).fill(trendData.avg),
                    borderColor: '#ef4444',
                    borderDash: [5, 5],
                    borderWidth: 2,
                    pointRadius: 0
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true } },
            plugins: { legend: { position: 'top', labels: { boxWidth: 12, font: { size: 10 } } } }
        }
    });

    // 1.4 ГЛУБИНА БРОНИРОВАНИЯ
    var ctxDepth = document.getElementById('chartBookingDepth').getContext('2d');
    var depthData = <?=json_encode($b1['booking_depth'], JSON_UNESCAPED_UNICODE)?>;
    new Chart(ctxDepth, {
        type: 'bar',
        data: {
            labels: Object.keys(depthData),
            datasets: [{
                label: 'Записи',
                data: Object.values(depthData),
                backgroundColor: '#10b981',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { x: { ticks: { font: { size: 10 } } } },
            plugins: { legend: { display: false } }
        }
    });

    // 2.1 ДОЛЯ КЛИЕНТОВ НА ХРАНЕНИИ
    var ctxStorage = document.getElementById('chartStorageDoughnut').getContext('2d');
    var storagePercent = <?=$b2['storage_percent']?>;
    new Chart(ctxStorage, {
        type: 'doughnut',
        data: {
            labels: ['Да (' + storagePercent + '%)', 'Нет (' + (100 - storagePercent).toFixed(1) + '%)'],
            datasets: [{
                data: [storagePercent, Math.max(0, 100 - storagePercent)],
                backgroundColor: ['#10b981', '#cbd5e1'],
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'right' } }
        }
    });

    // 2.2 ТИП КУЗОВА И ДИАМЕТРЫ ШИН
    var ctxStacked = document.getElementById('chartStackedBrands').getContext('2d');
    var stackedBodyData = <?=json_encode($b2['stacked_body_types'], JSON_UNESCAPED_UNICODE)?>;
    var bodyTypesList = Object.keys(stackedBodyData);

    new Chart(ctxStacked, {
        type: 'bar',
        data: {
            labels: bodyTypesList,
            datasets: [
                { label: 'R15', data: bodyTypesList.map(bt => stackedBodyData[bt]['R15'] || 0), backgroundColor: '#60a5fa' },
                { label: 'R16', data: bodyTypesList.map(bt => stackedBodyData[bt]['R16'] || 0), backgroundColor: '#2563eb' },
                { label: 'R17', data: bodyTypesList.map(bt => stackedBodyData[bt]['R17'] || 0), backgroundColor: '#1d4ed8' },
                { label: 'R18+', data: bodyTypesList.map(bt => stackedBodyData[bt]['R18+'] || 0), backgroundColor: '#1e3a8a' }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { x: { stacked: true }, y: { stacked: true } },
            plugins: { legend: { position: 'top', labels: { boxWidth: 10, font: { size: 10 } } } }
        }
    });
});

function filterByStorage() {
    var sel = document.getElementById('storageFilterSelect');
    if (sel) {
        sel.value = 'Y';
        document.getElementById('analyticsFilterForm').submit();
    }
}
</script>

<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php");
