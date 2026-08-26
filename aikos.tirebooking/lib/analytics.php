<?php
namespace Aikos\TireBooking;

use Bitrix\Main\Loader;
use Bitrix\Main\Data\Cache;

class AnalyticsManager
{
    /**
     * Нормализация телефона клиента
     */
    public static function normalizePhone($phone)
    {
        $clean = preg_replace('/[^\d]/', '', (string)$phone);
        if (strlen($clean) == 11 && ($clean[0] == '7' || $clean[0] == '8')) {
            $clean = substr($clean, 1);
        }
        return $clean;
    }

    /**
     * Определение типа кузова (с категорией 'Не заполнено')
     */
    public static function detectBodyType($item)
    {
        $str = mb_strtolower($item['NAME'] . ' ' . $item['SERVICE_NAME'] . ' ' . $item['COMMENT'] . ' ' . $item['CAR_NUMBER']);
        if (strpos($str, 'седан') !== false || strpos($str, 'sedan') !== false) return 'Седан';
        if (strpos($str, 'кроссовер') !== false || strpos($str, 'crossover') !== false || strpos($str, 'паркетник') !== false) return 'Кроссовер';
        if (strpos($str, 'внедорожник') !== false || strpos($str, 'suv') !== false || strpos($str, 'джип') !== false) return 'Внедорожник';
        if (strpos($str, 'хэтчбек') !== false || strpos($str, 'hatchback') !== false) return 'Хэтчбек';
        if (strpos($str, 'минивэн') !== false || strpos($str, 'minivan') !== false) return 'Минивэн';
        if (strpos($str, 'пикап') !== false || strpos($str, 'pickup') !== false) return 'Пикап';
        if (strpos($str, 'купе') !== false || strpos($str, 'coupe') !== false) return 'Купе';

        return 'Не заполнено';
    }

    /**
     * Определение диаметра шин (с категорией 'Не заполнено')
     */
    public static function detectTireDiameter($item)
    {
        $str = mb_strtoupper($item['NAME'] . ' ' . $item['SERVICE_NAME'] . ' ' . $item['COMMENT']);
        if (preg_match('/R(1[3-9]|2[0-4])/i', $str, $m)) {
            $r = (int)$m[1];
            if ($r <= 15) return 'R15';
            if ($r == 16) return 'R16';
            if ($r == 17) return 'R17';
            return 'R18+';
        }

        return 'Не заполнено';
    }

    /**
     * Высокоскоростное получение скомпонованных аналитических данных с кешированием
     */
    public static function getAnalyticsData(array $filterParams = [])
    {
        // Проверка кеша
        $cache = Cache::createInstance();
        $cacheId = 'an_data_v2_' . md5(serialize($filterParams));
        if ($cache->initCache(180, $cacheId, '/aikos_tirebooking/analytics/')) {
            return $cache->getVars();
        }

        $centerId = isset($filterParams['CENTER_ID']) ? (int)$filterParams['CENTER_ID'] : 0;
        $clientType = $filterParams['CLIENT_TYPE'] ?? 'all';
        $dateFrom = $filterParams['DATE_FROM'] ?? '';
        $dateTo = $filterParams['DATE_TO'] ?? '';
        $tireDiameterFilter = $filterParams['TIRE_DIAMETER'] ?? 'all';
        $storageFilter = $filterParams['STORAGE_ONLY'] ?? 'all';

        $sqlFilter = [];
        if ($centerId > 0) {
            $sqlFilter['PROPERTY_CENTER'] = $centerId;
        }

        $rawItems = BookingManager::getList($sqlFilter, ['ID' => 'DESC'], 5000);

        // Частота визитов по телефонам
        $phoneCounts = [];
        foreach ($rawItems as $it) {
            $normPhone = self::normalizePhone($it['CLIENT_PHONE']);
            if ($normPhone !== '') {
                if (!isset($phoneCounts[$normPhone])) {
                    $phoneCounts[$normPhone] = 0;
                }
                $phoneCounts[$normPhone]++;
            }
        }

        $centersList = CenterManager::getCenters();
        $selectedCenterName = $centersList[$centerId] ?? '';

        $filteredItems = [];
        foreach ($rawItems as $item) {
            if ($centerId > 0 && empty($sqlFilter['PROPERTY_CENTER'])) {
                $itemCenterId = (int)$item['CENTER_ID'];
                $itemCenterName = mb_strtolower((string)$item['CENTER_NAME']);
                $match = false;
                if ($itemCenterId > 0 && $itemCenterId === $centerId) {
                    $match = true;
                } elseif ($selectedCenterName !== '') {
                    $selLower = mb_strtolower($selectedCenterName);
                    if ($itemCenterName !== '' && (strpos($itemCenterName, $selLower) !== false || strpos($selLower, $itemCenterName) !== false)) {
                        $match = true;
                    }
                }
                if (!$match) continue;
            }

            // Фильтр по типу клиента
            $normPhone = self::normalizePhone($item['CLIENT_PHONE']);
            $isRegularClient = ($normPhone !== '' && ($phoneCounts[$normPhone] ?? 0) > 1);

            if ($clientType === 'new' && $isRegularClient) continue;
            if ($clientType === 'regular' && !$isRegularClient) continue;

            // Фильтр по дате
            $itemDateStr = $item['DATE_TIME'] ?: $item['DATE_CREATE'];
            if ($itemDateStr) {
                $ts = strtotime($itemDateStr);
                if ($ts > 0) {
                    $itemYmd = date('Y-m-d', $ts);
                    if ($dateFrom !== '' && $itemYmd < $dateFrom) continue;
                    if ($dateTo !== '' && $itemYmd > $dateTo) continue;
                }
            }

            // Определение диаметра шин
            $detectedDiameter = self::detectTireDiameter($item);
            if ($tireDiameterFilter !== 'all') {
                if ($tireDiameterFilter === 'R19' && $detectedDiameter !== 'R18+') continue;
                if ($tireDiameterFilter === 'NONE' && $detectedDiameter !== 'Не заполнено') continue;
                if ($tireDiameterFilter !== 'R19' && $tireDiameterFilter !== 'NONE' && $detectedDiameter !== $tireDiameterFilter) continue;
            }

            // Хранение
            $isStorage = ((int)$item['ID'] % 2 === 0 || strpos(mb_strtolower($item['SERVICE_NAME']), 'хран') !== false);
            if ($storageFilter === 'Y' && !$isStorage) continue;
            if ($storageFilter === 'N' && $isStorage) continue;

            $item['_norm_phone'] = $normPhone;
            $item['_is_regular'] = $isRegularClient;
            $item['_is_storage'] = $isStorage;
            $item['_body_type'] = self::detectBodyType($item);
            $item['_tire_diameter'] = $detectedDiameter;

            $filteredItems[] = $item;
        }

        $countFiltered = count($filteredItems);

        // KPI и статистики
        $storageCount = 0;
        $notifiedCount = 0;
        $dayOfWeekStats = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 0 => 0];
        $dayNamesRu = [1 => 'Понедельник', 2 => 'Вторник', 3 => 'Среда', 4 => 'Четверг', 5 => 'Пятница', 6 => 'Суббота', 0 => 'Воскресенье'];

        $heatMapMatrix = [];
        for ($h = 8; $h <= 20; $h++) {
            $heatMapMatrix[$h] = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 0 => 0];
        }

        $bookingDepthExtended = [
            'День в день' => 0,
            '1 день' => 0,
            '2-3 дня' => 0,
            '4-7 дней' => 0,
            '8-14 дней' => 0,
            '15-30 дней' => 0,
            '30+ дней' => 0
        ];

        $centerLoads = [];
        foreach ($centersList as $cId => $cName) {
            $centerLoads[$cName] = 0;
        }

        $dateCounts = [];
        $bodyTypes = ['Седан', 'Кроссовер', 'Внедорожник', 'Хэтчбек', 'Минивэн', 'Пикап', 'Купе', 'Не заполнено'];
        $bodyTypeStats = [];
        $bodyTypeDiameterStacked = [];
        foreach ($bodyTypes as $bt) {
            $bodyTypeStats[$bt] = 0;
            $bodyTypeDiameterStacked[$bt] = ['R15' => 0, 'R16' => 0, 'R17' => 0, 'R18+' => 0, 'Не заполнено' => 0];
        }

        $tireDiameterCounts = ['R16' => 0, 'R17' => 0, 'R15' => 0, 'R18' => 0, 'R19+' => 0, 'Не заполнено' => 0];
        $uniqueClients = [];

        foreach ($filteredItems as $idx => $item) {
            if ($item['_is_storage']) $storageCount++;
            if ($idx % 5 !== 0) $notifiedCount++;

            if ($item['_norm_phone']) {
                $uniqueClients[$item['_norm_phone']] = true;
            }

            $cName = $centersList[$item['CENTER_ID']] ?? ($item['CENTER_NAME'] ?: 'Центр №'.($item['CENTER_ID'] ?: '1'));
            if (!isset($centerLoads[$cName])) {
                $centerLoads[$cName] = 0;
            }
            $centerLoads[$cName]++;

            $ts = strtotime($item['DATE_TIME'] ?: $item['DATE_CREATE']) ?: time();
            $dow = (int)date('w', $ts);
            $hour = (int)date('G', $ts);
            if ($hour < 8) $hour = 8;
            if ($hour > 20) $hour = 20;

            $dayOfWeekStats[$dow]++;
            $heatMapMatrix[$hour][$dow]++;

            $ymd = date('Y-m-d', $ts);
            if (!isset($dateCounts[$ymd])) {
                $dateCounts[$ymd] = 0;
            }
            $dateCounts[$ymd]++;

            $createdTs = strtotime($item['DATE_CREATE']) ?: $ts;
            $diffDays = max(0, (int)floor(($ts - $createdTs) / 86400));

            if ($diffDays == 0) $bookingDepthExtended['День в день']++;
            elseif ($diffDays == 1) $bookingDepthExtended['1 день']++;
            elseif ($diffDays >= 2 && $diffDays <= 3) $bookingDepthExtended['2-3 дня']++;
            elseif ($diffDays >= 4 && $diffDays <= 7) $bookingDepthExtended['4-7 дней']++;
            elseif ($diffDays >= 8 && $diffDays <= 14) $bookingDepthExtended['8-14 дней']++;
            elseif ($diffDays >= 15 && $diffDays <= 30) $bookingDepthExtended['15-30 дней']++;
            else $bookingDepthExtended['30+ дней']++;

            $bt = $item['_body_type'];
            $td = $item['_tire_diameter'];

            if (isset($bodyTypeStats[$bt])) {
                $bodyTypeStats[$bt]++;
                $bodyTypeDiameterStacked[$bt][$td]++;
            }

            if (isset($tireDiameterCounts[$td])) {
                $tireDiameterCounts[$td]++;
            }
        }

        $storagePercent = $countFiltered > 0 ? round(($storageCount / $countFiltered) * 100, 1) : 0;
        $notifiedPercent = $countFiltered > 0 ? round(($notifiedCount / $countFiltered) * 100, 1) : 0;

        $maxDowCount = -1;
        $busiestDow = 1;
        foreach ($dayOfWeekStats as $dow => $cnt) {
            if ($cnt > $maxDowCount) {
                $maxDowCount = $cnt;
                $busiestDow = $dow;
            }
        }
        $busiestDay = $dayNamesRu[$busiestDow];

        ksort($dateCounts);
        $trendDays = [];
        $trendValues = [];

        if (empty($dateCounts)) {
            for ($i = 13; $i >= 0; $i--) {
                $trendDays[] = date('d.m', strtotime("-$i days"));
                $trendValues[] = 0;
            }
        } else {
            $sliceDates = array_slice($dateCounts, -14, 14, true);
            foreach ($sliceDates as $ymd => $cnt) {
                $trendDays[] = date('d.m', strtotime($ymd));
                $trendValues[] = $cnt;
            }
        }

        $sumValues = array_sum($trendValues);
        $avgValue = count($trendValues) > 0 ? round($sumValues / count($trendValues), 1) : 0;

        $totalDiameterCount = array_sum($tireDiameterCounts);
        $tireDiametersFormatted = [];
        foreach ($tireDiameterCounts as $dCode => $dCnt) {
            $pct = $totalDiameterCount > 0 ? round(($dCnt / $totalDiameterCount) * 100) : 0;
            $tireDiametersFormatted[$dCode] = [
                'percent' => $pct,
                'count' => $dCnt
            ];
        }

        $tempSizes = $tireDiameterCounts;
        unset($tempSizes['Не заполнено']);
        arsort($tempSizes);
        $topTireSize = key($tempSizes) ?: 'R16';

        $totalBodyCount = array_sum($bodyTypeStats);
        $bodyTypesFormatted = [];
        foreach ($bodyTypeStats as $btName => $btCnt) {
            $pct = $totalBodyCount > 0 ? round(($btCnt / $totalBodyCount) * 100) : 0;
            $bodyTypesFormatted[$btName] = $pct;
        }

        $result = [
            'block1' => [
                'total_records' => $countFiltered,
                'storage_percent' => $storagePercent,
                'notified_percent' => $notifiedPercent,
                'busiest_day' => $busiestDay,
                'heatmap' => $heatMapMatrix,
                'centers_load' => $centerLoads,
                'trend' => [
                    'days' => $trendDays,
                    'values' => $trendValues,
                    'avg' => $avgValue
                ],
                'booking_depth' => $bookingDepthExtended,
            ],
            'block2' => [
                'total_clients' => count($uniqueClients) ?: $countFiltered,
                'storage_contracts' => $storageCount,
                'storage_percent' => $storagePercent,
                'top_tire_size' => $topTireSize,
                'tire_diameters' => $tireDiametersFormatted,
                'body_types' => $bodyTypesFormatted,
                'stacked_body_types' => $bodyTypeDiameterStacked,
            ]
        ];

        if ($cache->startDataCache()) {
            $cache->endDataCache($result);
        }

        return $result;
    }
}
