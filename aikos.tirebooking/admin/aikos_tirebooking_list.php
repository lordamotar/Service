<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_before.php");

use Bitrix\Main\Loader;
use Aikos\TireBooking\BookingManager;
use Aikos\TireBooking\CenterManager;
use Aikos\TireBooking\AnalyticsManager;

$MODULE_ID = "aikos.tirebooking";
Loader::includeModule($MODULE_ID);
Loader::includeModule("iblock");

$bookingIb = (int)\Bitrix\Main\Config\Option::get($MODULE_ID, "IBLOCK_BOOKING_ID", 80);
$hasIblockAccess = \CIBlock::GetPermission($bookingIb) > "D";
$POST_RIGHT = $APPLICATION->GetGroupRight($MODULE_ID);
if ($POST_RIGHT <= "D" && !$hasIblockAccess) {
    $APPLICATION->AuthForm(GetMessage("ACCESS_DENIED"));
}

$APPLICATION->SetTitle("Отслеживание и мониторинг записей шиномонтажа");

// Персональные настройки фильтра текущего менеджера через CUserOptions
$userListFilter = \CUserOptions::GetOption($MODULE_ID, 'list_filter', []);

if (isset($_REQUEST['reset']) && $_REQUEST['reset'] === 'Y') {
    \CUserOptions::DeleteOption($MODULE_ID, 'list_filter');
    LocalRedirect("aikos_tirebooking_list.php?lang=" . LANGUAGE_ID);
}

if (isset($_REQUEST['SEARCH']) || isset($_REQUEST['CENTER_ID']) || isset($_REQUEST['DATE_FROM'])) {
    $dateFrom = trim($_REQUEST['DATE_FROM'] ?? '');
    $dateTo = trim($_REQUEST['DATE_TO'] ?? '');
    $centerId = isset($_REQUEST['CENTER_ID']) ? (int)$_REQUEST['CENTER_ID'] : 0;
    $searchQuery = trim($_REQUEST['SEARCH'] ?? '');
    $perPage = isset($_REQUEST['per_page']) ? max(10, (int)$_REQUEST['per_page']) : 25;

    \CUserOptions::SetOption($MODULE_ID, 'list_filter', [
        'DATE_FROM' => $dateFrom,
        'DATE_TO' => $dateTo,
        'CENTER_ID' => $centerId,
        'SEARCH' => $searchQuery,
        'PER_PAGE' => $perPage,
    ]);
} elseif (!empty($userListFilter) && is_array($userListFilter)) {
    $dateFrom = $userListFilter['DATE_FROM'] ?? '';
    $dateTo = $userListFilter['DATE_TO'] ?? '';
    $centerId = (int)($userListFilter['CENTER_ID'] ?? 0);
    $searchQuery = $userListFilter['SEARCH'] ?? '';
    $perPage = (int)($userListFilter['PER_PAGE'] ?? 25);
} else {
    $dateFrom = '';
    $dateTo = '';
    $centerId = 0;
    $searchQuery = '';
    $perPage = 25;
}

$page = isset($_REQUEST['page']) ? max(1, (int)$_REQUEST['page']) : 1;

$centers = CenterManager::getCenters();
$selectedCenterName = $centers[$centerId] ?? '';

// Полное общее количество всех элементов инфоблока в БД (100,000+)
$totalDbCount = BookingManager::getCount();

// Загрузка активных элементов для анализа и отображения
$allItems = BookingManager::getList([], ['ID' => 'DESC'], 5000);

// Фильтрация по центру и поиску
$baseFilteredItems = array_filter($allItems, function($item) use ($centerId, $selectedCenterName, $searchQuery) {
    if ($centerId > 0) {
        $itemCenterId = (int)$item['CENTER_ID'];
        $itemCenterName = mb_strtolower((string)$item['CENTER_NAME']);
        
        $match = false;
        if ($itemCenterId > 0 && $itemCenterId === $centerId) {
            $match = true;
        } elseif ($selectedCenterName !== '') {
            $selectedLower = mb_strtolower($selectedCenterName);
            if ($itemCenterName !== '' && (strpos($itemCenterName, $selectedLower) !== false || strpos($selectedLower, $itemCenterName) !== false)) {
                $match = true;
            } else {
                $cityParts = explode(',', str_replace('г.', '', $selectedCenterName));
                $cityName = trim($cityParts[0] ?? '');
                if ($cityName !== '' && mb_strlen($cityName) > 3) {
                    if (strpos(mb_strtolower($itemCenterName), mb_strtolower($cityName)) !== false || strpos(mb_strtolower((string)$item['NAME']), mb_strtolower($cityName)) !== false) {
                        $match = true;
                    }
                }
            }
        }
        if (!$match) return false;
    }

    if ($searchQuery !== '') {
        $q = mb_strtolower($searchQuery);
        $match = (
            strpos(mb_strtolower((string)$item['ID']), $q) !== false ||
            strpos(mb_strtolower((string)$item['CLIENT_NAME']), $q) !== false ||
            strpos(mb_strtolower((string)$item['CLIENT_PHONE']), $q) !== false ||
            strpos(mb_strtolower((string)$item['CAR_NUMBER']), $q) !== false ||
            strpos(mb_strtolower((string)$item['COMMENT']), $q) !== false
        );
        if (!$match) return false;
    }

    return true;
});

// Временные метки
$now = time();
$todayStr = date('Y-m-d', $now);
$tomorrowStr = date('Y-m-d', strtotime('+1 day', $now));

$thisWeekStartStr = date('Y-m-d', strtotime('monday this week', $now));
$thisWeekEndStr = date('Y-m-d', strtotime('sunday this week', $now));

$nextWeekStartStr = date('Y-m-d', strtotime('monday next week', $now));
$nextWeekEndStr = date('Y-m-d', strtotime('sunday next week', $now));

// Подсчет временных карточек
$periodStats = [
    'total' => ($centerId == 0 && $searchQuery === '') ? $totalDbCount : count($baseFilteredItems),
    'today' => 0,
    'tomorrow' => 0,
    'this_week' => 0,
    'next_week' => 0
];

foreach ($baseFilteredItems as $it) {
    $itemDateStr = $it['DATE_TIME'] ?: $it['DATE_CREATE'];
    if (!$itemDateStr) continue;

    $ts = strtotime($itemDateStr);
    if (!$ts) continue;

    $itemYmd = date('Y-m-d', $ts);

    if ($itemYmd === $todayStr) {
        $periodStats['today']++;
    }
    if ($itemYmd === $tomorrowStr) {
        $periodStats['tomorrow']++;
    }
    if ($itemYmd >= $thisWeekStartStr && $itemYmd <= $thisWeekEndStr) {
        $periodStats['this_week']++;
    }
    if ($itemYmd >= $nextWeekStartStr && $itemYmd <= $nextWeekEndStr) {
        $periodStats['next_week']++;
    }
}

// Фильтрация результирующего списка по выбору периода (DATE_FROM / DATE_TO)
$filteredItems = array_filter($baseFilteredItems, function($item) use ($dateFrom, $dateTo) {
    if ($dateFrom === '' && $dateTo === '') return true;

    $createYmd = !empty($item['DATE_CREATE']) ? date('Y-m-d', strtotime($item['DATE_CREATE'])) : '';
    $visitYmd = !empty($item['DATE_TIME']) ? date('Y-m-d', strtotime($item['DATE_TIME'])) : '';

    $matchCreate = true;
    if ($dateFrom !== '' && ($createYmd === '' || $createYmd < $dateFrom)) $matchCreate = false;
    if ($dateTo !== '' && ($createYmd === '' || $createYmd > $dateTo)) $matchCreate = false;

    $matchVisit = true;
    if ($dateFrom !== '' && ($visitYmd === '' || $visitYmd < $dateFrom)) $matchVisit = false;
    if ($dateTo !== '' && ($visitYmd === '' || $visitYmd > $dateTo)) $matchVisit = false;

    return ($matchCreate || $matchVisit);
});

// Переиндексация массива
$filteredItems = array_values($filteredItems);
$totalFiltered = count($filteredItems);

// Сортировка таблицы
$sortBy = trim($_REQUEST['sort'] ?? 'ID');
$sortOrder = mb_strtolower(trim($_REQUEST['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

usort($filteredItems, function($a, $b) use ($sortBy, $sortOrder) {
    $valA = $a[$sortBy] ?? '';
    $valB = $b[$sortBy] ?? '';
    
    if ($sortBy === 'ID') {
        $cmp = (int)$valA <=> (int)$valB;
    } elseif (in_array($sortBy, ['DATE_CREATE', 'DATE_TIME', 'TIME_FIRST_INTERVAL'])) {
        $tsA = strtotime((string)$valA) ?: 0;
        $tsB = strtotime((string)$valB) ?: 0;
        $cmp = $tsA <=> $tsB;
    } elseif ($sortBy === 'CENTER_ID') {
        $cmp = (int)$valA <=> (int)$valB;
    } else {
        $cmp = strnatcasecmp((string)$valA, (string)$valB);
    }
    
    return $sortOrder === 'asc' ? $cmp : -$cmp;
});

// Пагинация
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) $page = $totalPages;

$offset = ($page - 1) * $perPage;
$pageItems = array_slice($filteredItems, $offset, $perPage);

$queryParams = $_GET;
$buildSortUrl = function($col) use ($sortBy, $sortOrder, $queryParams) {
    $nextOrder = ($sortBy === $col && $sortOrder === 'asc') ? 'desc' : 'asc';
    $params = $queryParams;
    $params['sort'] = $col;
    $params['order'] = $nextOrder;
    unset($params['page']);
    return '?' . http_build_query($params);
};

$renderSortHeader = function($col, $title) use ($sortBy, $sortOrder, $buildSortUrl) {
    $isActive = ($sortBy === $col);
    $icon = $isActive ? ($sortOrder === 'asc' ? ' ▲' : ' ▼') : ' <span style="opacity:0.35; font-size:10px;">⇅</span>';
    $url = $buildSortUrl($col);
    $style = 'cursor:pointer; white-space:nowrap; user-select:none; ' . ($isActive ? 'color:#2563eb; background:#eff6ff;' : '');
    return '<th style="' . $style . '" onclick="location.href=\'' . $url . '\'">' .
           '<a href="' . $url . '" style="color:inherit; text-decoration:none; display:inline-flex; align-items:center; gap:4px;">' .
           htmlspecialcharsbx($title) . $icon .
           '</a></th>';
};

require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");
?>

<style>
.tb-container {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    color: #1e293b;
}

.tb-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
}

.tb-stat-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 14px;
    text-align: center;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
    transition: all 0.2s ease;
    user-select: none;
}

.tb-stat-card.clickable {
    cursor: pointer;
}

.tb-stat-card.clickable:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 12px rgba(0,0,0,0.08);
}

.tb-stat-card.active {
    box-shadow: 0 0 0 2px #2563eb, 0 4px 8px rgba(37,99,235,0.15);
}

.tb-stat-value {
    font-size: 26px;
    font-weight: 700;
    line-height: 1.2;
}

.tb-stat-label {
    font-size: 12px;
    color: #64748b;
    margin-top: 4px;
    font-weight: 500;
}

.tb-stat-blue { color: #0284c7; border-top: 4px solid #38bdf8; }
.tb-stat-cyan { color: #0891b2; border-top: 4px solid #06b6d4; }
.tb-stat-yellow { color: #d97706; border-top: 4px solid #fbbf24; }
.tb-stat-green { color: #16a34a; border-top: 4px solid #4ade80; }
.tb-stat-indigo { color: #4f46e5; border-top: 4px solid #818cf8; }
.tb-stat-purple { color: #9333ea; border-top: 4px solid #c084fc; }

.tb-filter-panel {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    padding: 16px;
    margin-bottom: 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.tb-filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    align-items: flex-end;
}

.tb-filter-group {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.tb-filter-group label {
    font-size: 12px;
    font-weight: 600;
    color: #475569;
}

.tb-filter-input, .tb-filter-select {
    padding: 7px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 13px;
    outline: none;
    background-color: #fff;
    transition: border-color 0.2s;
}

.tb-filter-input:focus, .tb-filter-select:focus {
    border-color: #2563eb;
    box-shadow: 0 0 0 2px rgba(37,99,235,0.1);
}

.tb-btn {
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: background-color 0.2s;
    text-decoration: none !important;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.tb-btn-primary { background: #2563eb; color: #fff !important; }
.tb-btn-primary:hover { background: #1d4ed8; }

.tb-btn-secondary { background: #f1f5f9; color: #475569 !important; border: 1px solid #cbd5e1; }
.tb-btn-secondary:hover { background: #e2e8f0; }

.tb-table-wrapper {
    background: #ffffff;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    overflow-x: auto;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}

.tb-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    text-align: left;
}

.tb-table th {
    background: #f8fafc;
    color: #475569;
    font-weight: 600;
    padding: 10px 12px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 12px;
    letter-spacing: 0.3px;
    transition: background-color 0.15s;
}

.tb-table th:hover {
    background-color: #f1f5f9;
}

.tb-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    vertical-align: middle;
}

.tb-table tbody tr {
    transition: background-color 0.15s;
    cursor: pointer;
}

.tb-table tbody tr:hover {
    background-color: #f8fafc;
}

.tb-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    white-space: nowrap;
}

.badge-new { background: #fef3c7; color: #b45309; }
.badge-confirmed { background: #dcfce7; color: #15803d; }
.badge-progress { background: #e0e7ff; color: #4338ca; }
.badge-completed { background: #f3e8ff; color: #7e22ce; }
.badge-cancelled { background: #fee2e2; color: #b91c1c; }

.car-badge {
    font-family: monospace;
    background: #f1f5f9;
    padding: 2px 6px;
    border-radius: 4px;
    border: 1px solid #cbd5e1;
    font-weight: 700;
    color: #0f172a;
    font-size: 12px;
}

.tb-pagination-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 16px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    font-size: 13px;
    color: #64748b;
}

.tb-pagination-links {
    display: flex;
    gap: 4px;
    align-items: center;
}

.tb-page-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 32px;
    height: 32px;
    padding: 0 6px;
    border-radius: 6px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #334155;
    text-decoration: none !important;
    font-weight: 500;
    font-size: 13px;
    transition: all 0.15s ease;
}

.tb-page-link:hover {
    background: #e2e8f0;
    color: #0f172a;
}

.tb-page-link.active {
    background: #2563eb;
    color: #ffffff;
    border-color: #2563eb;
    font-weight: 600;
}

.tb-page-link.disabled {
    opacity: 0.4;
    pointer-events: none;
    background: #f1f5f9;
}
</style>

<div class="tb-container">
    <div class="tb-nav-tabs" style="display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; padding-bottom: 0;">
        <a href="aikos_tirebooking_list.php?lang=<?=LANGUAGE_ID?>" class="tb-nav-tab active" style="padding: 10px 18px; font-weight: 600; text-decoration: none; color: #2563eb; border-bottom: 3px solid #2563eb; margin-bottom: -2px;">📋 Отслеживание записей</a>
        <a href="aikos_tirebooking_analytics.php?lang=<?=LANGUAGE_ID?>" class="tb-nav-tab" style="padding: 10px 18px; font-weight: 600; text-decoration: none; color: #64748b; border-bottom: 3px solid transparent; margin-bottom: -2px;">📊 Анализ и Дашборды</a>
        <a href="aikos_tirebooking_settings.php?lang=<?=LANGUAGE_ID?>" class="tb-nav-tab" style="padding: 10px 18px; font-weight: 600; text-decoration: none; color: #64748b; border-bottom: 3px solid transparent; margin-bottom: -2px;">⚙️ Настройки и Блокировка записей</a>
    </div>

    <div class="tb-stats-grid">
        <div class="tb-stat-card tb-stat-blue">
            <div class="tb-stat-value"><?=number_format($periodStats['total'], 0, '', ' ')?></div>
            <div class="tb-stat-label">Всего записей</div>
        </div>

        <div class="tb-stat-card tb-stat-cyan">
            <div class="tb-stat-value"><?=number_format($totalFiltered, 0, '', ' ')?></div>
            <div class="tb-stat-label">Отфильтровано</div>
        </div>

        <?php $isTodayActive = ($dateFrom === $todayStr && $dateTo === $todayStr); ?>
        <div class="tb-stat-card tb-stat-yellow clickable <?=$isTodayActive ? 'active' : ''?>" onclick="filterByPeriod('<?=$todayStr?>', '<?=$todayStr?>')">
            <div class="tb-stat-value"><?=$periodStats['today']?></div>
            <div class="tb-stat-label">Сегодня</div>
        </div>

        <?php $isTomorrowActive = ($dateFrom === $tomorrowStr && $dateTo === $tomorrowStr); ?>
        <div class="tb-stat-card tb-stat-green clickable <?=$isTomorrowActive ? 'active' : ''?>" onclick="filterByPeriod('<?=$tomorrowStr?>', '<?=$tomorrowStr?>')">
            <div class="tb-stat-value"><?=$periodStats['tomorrow']?></div>
            <div class="tb-stat-label">Завтра</div>
        </div>

        <?php $isThisWeekActive = ($dateFrom === $thisWeekStartStr && $dateTo === $thisWeekEndStr); ?>
        <div class="tb-stat-card tb-stat-indigo clickable <?=$isThisWeekActive ? 'active' : ''?>" onclick="filterByPeriod('<?=$thisWeekStartStr?>', '<?=$thisWeekEndStr?>')">
            <div class="tb-stat-value"><?=$periodStats['this_week']?></div>
            <div class="tb-stat-label">На этой неделе</div>
        </div>

        <?php $isNextWeekActive = ($dateFrom === $nextWeekStartStr && $dateTo === $nextWeekEndStr); ?>
        <div class="tb-stat-card tb-stat-purple clickable <?=$isNextWeekActive ? 'active' : ''?>" onclick="filterByPeriod('<?=$nextWeekStartStr?>', '<?=$nextWeekEndStr?>')">
            <div class="tb-stat-value"><?=$periodStats['next_week']?></div>
            <div class="tb-stat-label">На следующей неделе</div>
        </div>
    </div>

    <div class="tb-filter-panel">
        <form method="get" action="" id="tb-filter-form" class="tb-filter-row">
            <input type="hidden" name="sort" value="<?=htmlspecialcharsbx($sortBy)?>">
            <input type="hidden" name="order" value="<?=htmlspecialcharsbx($sortOrder)?>">

            <div class="tb-filter-group">
                <label>Поиск (ФИО / Тел / Госномер):</label>
                <input type="text" name="SEARCH" value="<?=htmlspecialcharsbx($searchQuery)?>" placeholder="Поиск по клиенту..." class="tb-filter-input" style="width: 220px;">
            </div>

            <div class="tb-filter-group">
                <label>Шинный центр:</label>
                <select name="CENTER_ID" class="tb-filter-select" style="max-width: 280px;">
                    <option value="0">--- Все центры ---</option>
                    <?php foreach ($centers as $id => $name): ?>
                        <option value="<?=$id?>" <?=$id == $centerId ? 'selected' : ''?>><?=htmlspecialcharsbx($name)?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="tb-filter-group">
                <label>Период с:</label>
                <input type="date" name="DATE_FROM" id="field-date-from" value="<?=htmlspecialcharsbx($dateFrom)?>" class="tb-filter-input">
            </div>

            <div class="tb-filter-group">
                <label>по:</label>
                <input type="date" name="DATE_TO" id="field-date-to" value="<?=htmlspecialcharsbx($dateTo)?>" class="tb-filter-input">
            </div>

            <div class="tb-filter-group">
                <label>На странице:</label>
                <select name="per_page" class="tb-filter-select" onchange="this.form.submit()">
                    <option value="25" <?=$perPage == 25 ? 'selected' : ''?>>25</option>
                    <option value="50" <?=$perPage == 50 ? 'selected' : ''?>>50</option>
                    <option value="100" <?=$perPage == 100 ? 'selected' : ''?>>100</option>
                    <option value="500" <?=$perPage == 500 ? 'selected' : ''?>>500</option>
                </select>
            </div>

            <div style="display: flex; gap: 8px;">
                <button type="submit" class="tb-btn tb-btn-primary">🔍 Применить</button>
                <a href="aikos_tirebooking_list.php?lang=<?=LANGUAGE_ID?>&reset=Y" class="tb-btn tb-btn-secondary">✖ Сбросить</a>
            </div>
        </form>
    </div>

    <div class="tb-table-wrapper">
        <table class="tb-table">
            <thead>
                <tr>
                    <?=$renderSortHeader('ID', 'ID')?>
                    <?=$renderSortHeader('DATE_CREATE', 'Дата создания')?>
                    <?=$renderSortHeader('CLIENT_NAME', 'Имя и фамилия')?>
                    <?=$renderSortHeader('CLIENT_PHONE', 'Телефон')?>
                    <?=$renderSortHeader('CAR_NUMBER', 'Гос. номер')?>
                    <?=$renderSortHeader('BODY_TYPE', 'Тип кузова')?>
                    <?=$renderSortHeader('TIRE_DIAMETER', 'Диаметр')?>
                    <?=$renderSortHeader('DATE_TIME', 'Дата визита')?>
                    <?=$renderSortHeader('IS_STORAGE', 'Хранение')?>
                    <?=$renderSortHeader('NOTIFICATION_STATUS', 'Уведомление')?>
                    <?=$renderSortHeader('CENTER_ID', 'Шинный центр')?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pageItems)): ?>
                    <tr>
                        <td colspan="11" style="text-align: center; padding: 30px; color: #94a3b8;">
                            Записи по выбранному фильтру не найдены
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pageItems as $item): 
                        $isStorage = !empty($item['IS_STORAGE']) && mb_strtolower($item['IS_STORAGE']) !== 'нет' && mb_strtolower($item['IS_STORAGE']) !== 'n' && mb_strtolower($item['IS_STORAGE']) !== '0';
                        $notif = $item['NOTIFICATION_STATUS'];
                        if (!$notif && in_array(mb_strtolower($item['NAME']), ['ожидание', 'уведомлен', 'уведомлено'])) {
                            $notif = $item['NAME'];
                        }
                        $notifLower = mb_strtolower((string)$notif);
                        $notifBadge = 'badge-new';
                        if (strpos($notifLower, 'уведомлен') !== false) $notifBadge = 'badge-confirmed';
                        elseif (strpos($notifLower, 'ожидан') !== false) $notifBadge = 'badge-progress';

                        $bodyDetected = AnalyticsManager::detectBodyType($item);
                        $diamDetected = AnalyticsManager::detectTireDiameter($item);
                        $bodyDisplay = ($bodyDetected !== 'Не заполнено') ? $bodyDetected : ($item['BODY_TYPE'] ?: '—');
                        $diamDisplay = ($diamDetected !== 'Не заполнено') ? $diamDetected : ($item['TIRE_DIAMETER'] ? mb_strtoupper($item['TIRE_DIAMETER']) : '—');
                    ?>
                        <tr onclick="showDetails(<?=htmlspecialcharsbx(json_encode($item, JSON_HEX_APOS | JSON_HEX_QUOT))?>)">
                            <td><strong>#<?=$item['ID']?></strong></td>
                            <td style="white-space: nowrap; font-size: 12px; color: #64748b;"><?=htmlspecialcharsbx($item['DATE_CREATE'])?></td>
                            <td>
                                <div style="font-weight: 600; color: #0f172a;"><?=htmlspecialcharsbx($item['CLIENT_NAME'])?></div>
                                <?php if (!empty($item['IIN'])): ?>
                                    <div style="font-size: 11px; color: #94a3b8;">ИИН: <?=htmlspecialcharsbx($item['IIN'])?></div>
                                <?php endif; ?>
                            </td>
                            <td style="white-space: nowrap;"><?=htmlspecialcharsbx($item['CLIENT_PHONE'] ?: '—')?></td>
                            <td>
                                <?php if ($item['CAR_NUMBER']): ?>
                                    <span class="car-badge"><?=htmlspecialcharsbx($item['CAR_NUMBER'])?></span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?=htmlspecialcharsbx($bodyDisplay)?></td>
                            <td><strong><?=htmlspecialcharsbx($diamDisplay)?></strong></td>
                            <td style="white-space: nowrap; font-weight: 500;"><?=htmlspecialcharsbx($item['DATE_TIME'])?></td>
                            <td>
                                <?php if ($isStorage): ?>
                                    <span class="tb-badge badge-confirmed">Да<?=$item['STORAGE_CONTRACT'] ? ' (№' . htmlspecialcharsbx($item['STORAGE_CONTRACT']) . ')' : ''?></span>
                                <?php else: ?>
                                    <span style="color: #94a3b8;">Нет</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($notif): ?>
                                    <span class="tb-badge <?=$notifBadge?>"><?=htmlspecialcharsbx($notif)?></span>
                                <?php else: ?>
                                    <span style="color: #94a3b8;">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 12px;">
                                <?=$centers[$item['CENTER_ID']] ?? ($item['CENTER_NAME'] ?: ($item['CENTER_ID'] ? 'Центр #'.$item['CENTER_ID'] : '—'))?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($totalFiltered > 0): ?>
            <?php 
                $startItem = $offset + 1;
                $endItem = min($offset + $perPage, $totalFiltered);
                
                $pageQueryParams = $_GET;
                unset($pageQueryParams['page']);
                $buildUrl = function($p) use ($pageQueryParams) {
                    $pageQueryParams['page'] = $p;
                    return '?' . http_build_query($pageQueryParams);
                };
            ?>
            <div class="tb-pagination-bar">
                <div>
                    Показано <strong><?=$startItem?> - <?=$endItem?></strong> из <strong><?=$totalFiltered?></strong> записей
                </div>
                <div class="tb-pagination-links">
                    <a href="<?=$buildUrl($page - 1)?>" class="tb-page-link <?=$page <= 1 ? 'disabled' : ''?>">‹ Назад</a>
                    
                    <?php 
                        $startP = max(1, $page - 2);
                        $endP = min($totalPages, $page + 2);
                        for ($p = $startP; $p <= $endP; $p++):
                    ?>
                        <a href="<?=$buildUrl($p)?>" class="tb-page-link <?=$p == $page ? 'active' : ''?>"><?=$p?></a>
                    <?php endfor; ?>
                    
                    <a href="<?=$buildUrl($page + 1)?>" class="tb-page-link <?=$page >= $totalPages ? 'disabled' : ''?>">Вперёд ›</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="detail-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.5); z-index:9999; justify-content:center; align-items:center;">
    <div style="background:#fff; border-radius:12px; max-width:650px; width:90%; padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:16px;">
            <h3 style="margin:0; font-size:18px; color:#0f172a;" id="modal-title">Детали записи</h3>
            <button onclick="closeModal()" style="background:none; border:none; font-size:20px; cursor:pointer; color:#64748b;">✕</button>
        </div>
        <div id="modal-content" style="font-size:13px; line-height:1.6; color:#334155;"></div>
        <div style="text-align:right; margin-top:20px; border-top:1px solid #e2e8f0; padding-top:12px;">
            <button class="tb-btn tb-btn-secondary" onclick="closeModal()">Закрыть</button>
        </div>
    </div>
</div>

<script>
function filterByPeriod(from, to) {
    document.getElementById('field-date-from').value = from;
    document.getElementById('field-date-to').value = to;
    document.getElementById('tb-filter-form').submit();
}

function showDetails(item) {
    document.getElementById('modal-title').innerText = 'Запись на шиномонтаж #' + item.ID;
    
    var storageStr = item.IS_STORAGE || 'Нет';
    if (item.STORAGE_CONTRACT) {
        storageStr += ' (Договор №' + item.STORAGE_CONTRACT + ')';
    }

    var html = '<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">' +
        '<div><strong>Клиент (ФИО):</strong> ' + (item.CLIENT_NAME || '—') + '</div>' +
        '<div><strong>Телефон:</strong> ' + (item.CLIENT_PHONE || '—') + '</div>' +
        '<div><strong>Гос. номер:</strong> ' + (item.CAR_NUMBER || '—') + '</div>' +
        '<div><strong>Тип кузова:</strong> ' + (item.BODY_TYPE || '—') + '</div>' +
        '<div><strong>Диаметр шин:</strong> ' + (item.TIRE_DIAMETER || '—') + '</div>' +
        '<div><strong>Дата визита:</strong> ' + (item.DATE_TIME || '—') + '</div>' +
        '<div><strong>Дата создания:</strong> ' + (item.DATE_CREATE || '—') + '</div>' +
        '<div><strong>Хранение:</strong> ' + storageStr + '</div>' +
        '<div><strong>Уведомление:</strong> ' + (item.NOTIFICATION_STATUS || item.NAME || '—') + '</div>' +
        (item.IIN ? '<div><strong>ИИН:</strong> ' + item.IIN + '</div>' : '') +
        '<div><strong>Шинный центр:</strong> ' + (item.CENTER_NAME || item.CENTER_ID || '—') + '</div>' +
        '<div><strong>Статус:</strong> ' + (item.STATUS || '—') + '</div>' +
        '</div>';
    
    if (item.COMMENT) {
        html += '<div style="margin-top:14px; background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;"><strong>Комментарий / Услуга:</strong><br>' + item.COMMENT + '</div>';
    }
    
    document.getElementById('modal-content').innerHTML = html;
    document.getElementById('detail-modal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('detail-modal').style.display = 'none';
}
</script>

<?php
require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/epilog_admin.php");
