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

// Захват параметров фильтрации и пагинации
$dateFrom = trim($_REQUEST['DATE_FROM'] ?? '');
$dateTo = trim($_REQUEST['DATE_TO'] ?? '');
$centerId = isset($_REQUEST['CENTER_ID']) ? (int)$_REQUEST['CENTER_ID'] : 0;
$searchQuery = trim($_REQUEST['SEARCH'] ?? '');

$page = isset($_REQUEST['page']) ? max(1, (int)$_REQUEST['page']) : 1;
$perPage = isset($_REQUEST['per_page']) ? max(10, (int)$_REQUEST['per_page']) : 25;

$centers = CenterManager::getCenters();
$selectedCenterName = $centers[$centerId] ?? '';

// Получение исходного набора данных из инфоблока
$allItems = BookingManager::getList([], ['ID' => 'DESC'], 2000);

// Полная фильтрация на основе переданных параметров
$filteredItems = array_filter($allItems, function($item) use ($centerId, $selectedCenterName, $dateFrom, $dateTo, $searchQuery) {
    // 1. Фильтр по Шинному центру
    if ($centerId > 0) {
        $itemCenterId = (int)$item['CENTER_ID'];
        $itemCenterName = mb_strtolower((string)$item['CENTER_NAME']);
        
        $match = false;
        if ($itemCenterId > 0 && $itemCenterId === $centerId) {
            $match = true;
        } elseif ($selectedCenterName !== '') {
            $selectedLower = mb_strtolower($selectedCenterName);
            // Проверка по совпадению наименования центра или города/улицы
            if ($itemCenterName !== '' && (strpos($itemCenterName, $selectedLower) !== false || strpos($selectedLower, $itemCenterName) !== false)) {
                $match = true;
            } else {
                // Извлекаем ключевую часть адреса (например "Актобе" или "Кенес Нокина")
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

    // 2. Фильтр по диапазону дат (DATE_FROM / DATE_TO)
    $itemDateStr = $item['DATE_TIME'] ?: $item['DATE_CREATE'];
    if ($itemDateStr) {
        $ts = strtotime($itemDateStr);
        if ($ts > 0) {
            $itemYmd = date('Y-m-d', $ts);
            if ($dateFrom !== '' && $itemYmd < $dateFrom) {
                return false;
            }
            if ($dateTo !== '' && $itemYmd > $dateTo) {
                return false;
            }
        }
    }

    // 3. Поисковой фильтр (ФИО / Телефон / Госномер)
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

// Переиндексация отфильтрованного массива
$filteredItems = array_values($filteredItems);
$totalFiltered = count($filteredItems);

// Расчет параметров пагинации
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) $page = $totalPages;

$offset = ($page - 1) * $perPage;
$pageItems = array_slice($filteredItems, $offset, $perPage);

// Вычисление сводной статистики
$stats = [
    'total' => $totalFiltered,
    'new' => 0,
    'confirmed' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'cancelled' => 0
];
foreach ($filteredItems as $it) {
    $s = mb_strtolower($it['STATUS']);
    if (strpos($s, 'нов') !== false) $stats['new']++;
    elseif (strpos($s, 'подтвержд') !== false) $stats['confirmed']++;
    elseif (strpos($s, 'работ') !== false || strpos($s, 'процесс') !== false) $stats['in_progress']++;
    elseif (strpos($s, 'заверш') !== false || strpos($s, 'выполн') !== false) $stats['completed']++;
    elseif (strpos($s, 'отмен') !== false) $stats['cancelled']++;
    else $stats['new']++;
}

require_once($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_admin_after.php");
?>

<style>
.tb-container {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    color: #1e293b;
}

.tb-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
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
}

.tb-stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px rgba(0,0,0,0.06);
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
    overflow: hidden;
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
    padding: 10px 14px;
    border-bottom: 1px solid #e2e8f0;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.tb-table td {
    padding: 11px 14px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
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
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
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
    font-weight: 600;
    color: #0f172a;
}

/* Пагинация в стиле callcenter_orders.php */
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
    <!-- Сводная статистика -->
    <div class="tb-stats-grid">
        <div class="tb-stat-card tb-stat-blue">
            <div class="tb-stat-value"><?=$stats['total']?></div>
            <div class="tb-stat-label">Всего записей</div>
        </div>
        <div class="tb-stat-card tb-stat-yellow">
            <div class="tb-stat-value"><?=$stats['new']?></div>
            <div class="tb-stat-label">Новые</div>
        </div>
        <div class="tb-stat-card tb-stat-green">
            <div class="tb-stat-value"><?=$stats['confirmed']?></div>
            <div class="tb-stat-label">Подтверждённые</div>
        </div>
        <div class="tb-stat-card tb-stat-indigo">
            <div class="tb-stat-value"><?=$stats['in_progress']?></div>
            <div class="tb-stat-label">В работе</div>
        </div>
        <div class="tb-stat-card tb-stat-purple">
            <div class="tb-stat-value"><?=$stats['completed']?></div>
            <div class="tb-stat-label">Завершено</div>
        </div>
    </div>

    <!-- Фильтр-панель -->
    <div class="tb-filter-panel">
        <form method="get" action="" class="tb-filter-row">
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
                <input type="date" name="DATE_FROM" value="<?=htmlspecialcharsbx($dateFrom)?>" class="tb-filter-input">
            </div>

            <div class="tb-filter-group">
                <label>по:</label>
                <input type="date" name="DATE_TO" value="<?=htmlspecialcharsbx($dateTo)?>" class="tb-filter-input">
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
                <a href="?" class="tb-btn tb-btn-secondary">✖ Сбросить</a>
            </div>
        </form>
    </div>

    <!-- Таблица записей -->
    <div class="tb-table-wrapper">
        <table class="tb-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Клиент / Запись</th>
                    <th>Телефон</th>
                    <th>Гос. номер</th>
                    <th>Дата и время</th>
                    <th>Статус</th>
                    <th>Шинный центр</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($pageItems)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 30px; color: #94a3b8;">
                            Записи по выбранному фильтру не найдены
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($pageItems as $item): 
                        $st = mb_strtolower($item['STATUS']);
                        $badgeClass = 'badge-new';
                        if (strpos($st, 'подтвержд') !== false) $badgeClass = 'badge-confirmed';
                        elseif (strpos($st, 'работ') !== false || strpos($st, 'процесс') !== false) $badgeClass = 'badge-progress';
                        elseif (strpos($st, 'заверш') !== false) $badgeClass = 'badge-completed';
                        elseif (strpos($st, 'отмен') !== false) $badgeClass = 'badge-cancelled';
                    ?>
                        <tr onclick="showDetails(<?=htmlspecialcharsbx(json_encode($item, JSON_HEX_APOS | JSON_HEX_QUOT))?>)">
                            <td><strong>#<?=$item['ID']?></strong></td>
                            <td>
                                <div style="font-weight: 600; color: #0f172a;"><?=htmlspecialcharsbx($item['CLIENT_NAME'])?></div>
                                <?php if ($item['SERVICE_NAME']): ?>
                                    <div style="font-size: 11px; color: #64748b;"><?=htmlspecialcharsbx($item['SERVICE_NAME'])?></div>
                                <?php endif; ?>
                            </td>
                            <td><?=htmlspecialcharsbx($item['CLIENT_PHONE'] ?: '—')?></td>
                            <td>
                                <?php if ($item['CAR_NUMBER']): ?>
                                    <span class="car-badge"><?=htmlspecialcharsbx($item['CAR_NUMBER'])?></span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?=htmlspecialcharsbx($item['DATE_TIME'])?></td>
                            <td>
                                <span class="tb-badge <?=$badgeClass?>">
                                    <?=htmlspecialcharsbx($item['STATUS'])?>
                                </span>
                            </td>
                            <td>
                                <?=$centers[$item['CENTER_ID']] ?? ($item['CENTER_NAME'] ?: ($item['CENTER_ID'] ? 'Центр #'.$item['CENTER_ID'] : '—'))?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Блок пагинации -->
        <?php if ($totalFiltered > 0): ?>
            <?php 
                $startItem = $offset + 1;
                $endItem = min($offset + $perPage, $totalFiltered);
                
                // Формирование URL параметров пагинации
                $queryParams = $_GET;
                unset($queryParams['page']);
                $buildUrl = function($p) use ($queryParams) {
                    $queryParams['page'] = $p;
                    return '?' . http_build_query($queryParams);
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

<!-- Модальное окно просмотра деталей записи -->
<div id="detail-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.5); z-index:9999; justify-content:center; align-items:center;">
    <div style="background:#fff; border-radius:12px; max-width:600px; width:90%; padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
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
function showDetails(item) {
    document.getElementById('modal-title').innerText = 'Запись на шиномонтаж #' + item.ID;
    var html = '<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">' +
        '<div><strong>Клиент:</strong> ' + (item.CLIENT_NAME || '—') + '</div>' +
        '<div><strong>Телефон:</strong> ' + (item.CLIENT_PHONE || '—') + '</div>' +
        '<div><strong>Гос. номер:</strong> ' + (item.CAR_NUMBER || '—') + '</div>' +
        '<div><strong>Дата и время:</strong> ' + (item.DATE_TIME || '—') + '</div>' +
        '<div><strong>Статус:</strong> ' + (item.STATUS || '—') + '</div>' +
        '<div><strong>Шинный центр:</strong> ' + (item.CENTER_NAME || item.CENTER_ID || '—') + '</div>' +
        '</div>';
    
    if (item.COMMENT) {
        html += '<div style="margin-top:14px; background:#f8fafc; padding:10px; border-radius:6px; border:1px solid #e2e8f0;"><strong>Комментарий:</strong><br>' + item.COMMENT + '</div>';
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
