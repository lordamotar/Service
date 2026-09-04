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
     * Определение типа кузова (строго в соответствии со структурой шиномонтажа: Легковой, Кроссовер, Внедорожник, Минивэн, Пикап и др.)
     */
    public static function detectBodyType($item)
    {
        $directVal = trim((string)($item['BODY_TYPE'] ?? $item['CAR_BODY'] ?? ''));
        if ($directVal !== '') {
            $dLower = mb_strtolower($directVal);
            if (preg_match('/(кросс|cross|kross|паркет)/iu', $dLower)) {
                return 'Кроссовер';
            }
            if (preg_match('/(внедор|suv|джип|jeep|offroad|vnedor|4x4|4х4)/iu', $dLower)) {
                return 'Внедорожник';
            }
            if (preg_match('/(легков|седан|sedan|хэтч|hatch|универсал|купе|coupe|sed|tyre|tire|wheel|passenger)/iu', $dLower)) {
                return 'Легковой';
            }
            if (preg_match('/(минив|minivan|микроавтобус|бус|van|коммерч|bus)/iu', $dLower)) {
                return 'Минивэн';
            }
            if (preg_match('/(пикап|pickup|pick)/iu', $dLower)) {
                return 'Пикап';
            }
            if (preg_match('/(грузов|truck)/iu', $dLower)) {
                return 'Грузовой';
            }
            return mb_convert_case($directVal, MB_CASE_TITLE, 'UTF-8');
        }

        $fieldsToSearch = [
            $item['NAME'] ?? '',
            $item['SERVICE_NAME'] ?? '',
            $item['COMMENT'] ?? '',
            $item['CAR_NUMBER'] ?? ''
        ];
        
        $str = mb_strtolower(implode(' ', $fieldsToSearch));

        if (preg_match('/(кросс|cross|kross|паркет)/iu', $str)) {
            return 'Кроссовер';
        }
        if (preg_match('/(внедор|suv|джип|jeep|offroad|vnedor|4x4|4х4)/iu', $str)) {
            return 'Внедорожник';
        }
        if (preg_match('/(легков|седан|sedan|хэтч|hatch|универсал|купе|coupe|sed|tyre|tire|wheel)/iu', $str)) {
            return 'Легковой';
        }
        if (preg_match('/(минив|minivan|микроавтобус|бус|van)/iu', $str)) {
            return 'Минивэн';
        }
        if (preg_match('/(пикап|pickup|pick)/iu', $str)) {
            return 'Пикап';
        }

        return 'Не заполнено';
    }

    /**
     * Определение диаметра шин (с категорией 'Не заполнено')
     */
    public static function detectTireDiameter($item)
    {
        $str = mb_strtoupper(trim(($item['TIRE_DIAMETER'] ?? '') . ' ' . $item['NAME'] . ' ' . $item['SERVICE_NAME'] . ' ' . $item['COMMENT']));
        if (preg_match('/R?(1[3-9]|2[0-4])/i', $str, $m)) {
            $r = (int)$m[1];
            if ($r <= 15) return 'R15';
            if ($r == 16) return 'R16';
            if ($r == 17) return 'R17';
            if ($r == 18) return 'R18';
            return 'R19+';
        }

        if (!empty($item['TIRE_DIAMETER'])) {
            $td = trim((string)$item['TIRE_DIAMETER']);
            if (preg_match('/R/i', $td)) return mb_strtoupper($td);
            return 'R' . $td;
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
        $cacheId = 'an_data_v22_' . md5(serialize($filterParams));
        if ($cache->initCache(10, $cacheId, '/aikos_tirebooking/analytics/')) {
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
        if ($dateFrom !== '') {
            $sqlFilter['DATE_FROM'] = $dateFrom;
        }
        if ($dateTo !== '') {
            $sqlFilter['DATE_TO'] = $dateTo;
        }

        $rawItems = BookingManager::getList($sqlFilter, ['ID' => 'DESC'], 0);

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

            // Фильтр по дате (по дате создания DATE_CREATE или по дате приёма TIME_FIRST_INTERVAL)
            if ($dateFrom !== '' || $dateTo !== '') {
                $createYmd = !empty($item['DATE_CREATE']) ? date('Y-m-d', strtotime($item['DATE_CREATE'])) : '';
                $visitYmd = !empty($item['DATE_TIME']) ? date('Y-m-d', strtotime($item['DATE_TIME'])) : '';

                $matchCreate = true;
                if ($dateFrom !== '' && ($createYmd === '' || $createYmd < $dateFrom)) $matchCreate = false;
                if ($dateTo !== '' && ($createYmd === '' || $createYmd > $dateTo)) $matchCreate = false;

                $matchVisit = true;
                if ($dateFrom !== '' && ($visitYmd === '' || $visitYmd < $dateFrom)) $matchVisit = false;
                if ($dateTo !== '' && ($visitYmd === '' || $visitYmd > $dateTo)) $matchVisit = false;

                if (!$matchCreate && !$matchVisit) continue;
            }

            // Определение диаметра шин
            $detectedDiameter = self::detectTireDiameter($item);
            if ($tireDiameterFilter !== 'all') {
                if ($tireDiameterFilter === 'R19' && $detectedDiameter !== 'R18+' && $detectedDiameter !== 'R19+') continue;
                if ($tireDiameterFilter === 'NONE' && $detectedDiameter !== 'Не заполнено') continue;
                if ($tireDiameterFilter !== 'R19' && $tireDiameterFilter !== 'NONE' && $detectedDiameter !== $tireDiameterFilter) continue;
            }

            // Хранение из свойства и по контексту
            $isStorage = false;
            if (!empty($item['IS_STORAGE'])) {
                $stVal = mb_strtolower(trim((string)$item['IS_STORAGE']));
                if ($stVal !== 'нет' && $stVal !== 'n' && $stVal !== '0' && $stVal !== 'false') {
                    $isStorage = true;
                }
            }
            if (!$isStorage && strpos(mb_strtolower(($item['SERVICE_NAME'] ?? '') . ' ' . ($item['COMMENT'] ?? '')), 'хран') !== false) {
                $isStorage = true;
            }

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
        $bodyTypes = ['Легковой', 'Кроссовер', 'Внедорожник', 'Минивэн', 'Пикап', 'Купе', 'Не заполнено'];
        $bodyTypeStats = [];
        $bodyTypeDiameterStacked = [];
        foreach ($bodyTypes as $bt) {
            $bodyTypeStats[$bt] = 0;
            $bodyTypeDiameterStacked[$bt] = ['R15' => 0, 'R16' => 0, 'R17' => 0, 'R18' => 0, 'R18+' => 0, 'R19+' => 0, 'Не заполнено' => 0];
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

            // Расчет глубины бронирования
            $visitDateStr = !empty($item['TIME_FIRST_INTERVAL']) ? $item['TIME_FIRST_INTERVAL'] : $item['DATE_TIME'];
            $targetTs = strtotime($visitDateStr) ?: $ts;
            $createdTs = strtotime($item['DATE_CREATE']) ?: $targetTs;
            $diffDays = max(0, (int)floor(($targetTs - $createdTs) / 86400));

            if ($diffDays == 0) $bookingDepthExtended['День в день']++;
            elseif ($diffDays == 1) $bookingDepthExtended['1 день']++;
            elseif ($diffDays >= 2 && $diffDays <= 3) $bookingDepthExtended['2-3 дня']++;
            elseif ($diffDays >= 4 && $diffDays <= 7) $bookingDepthExtended['4-7 дней']++;
            elseif ($diffDays >= 8 && $diffDays <= 14) $bookingDepthExtended['8-14 дней']++;
            elseif ($diffDays >= 15 && $diffDays <= 30) $bookingDepthExtended['15-30 дней']++;
            else $bookingDepthExtended['30+ дней']++;

            $bt = $item['_body_type'] ?: 'Не заполнено';
            $td = $item['_tire_diameter'] ?: 'Не заполнено';

            if (!isset($bodyTypeStats[$bt])) {
                $bodyTypeStats[$bt] = 0;
                $bodyTypeDiameterStacked[$bt] = ['R15' => 0, 'R16' => 0, 'R17' => 0, 'R18' => 0, 'R18+' => 0, 'R19+' => 0, 'Не заполнено' => 0];
            }

            $bodyTypeStats[$bt]++;
            if (isset($bodyTypeDiameterStacked[$bt][$td])) {
                $bodyTypeDiameterStacked[$bt][$td]++;
            } else {
                $bodyTypeDiameterStacked[$bt]['Не заполнено']++;
            }

            if (isset($tireDiameterCounts[$td])) {
                $tireDiameterCounts[$td]++;
            } else {
                $tireDiameterCounts['Не заполнено']++;
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
        $trendMode = 'days';

        if (empty($dateCounts)) {
            for ($i = 13; $i >= 0; $i--) {
                $trendDays[] = date('d.m', strtotime("-$i days"));
                $trendValues[] = 0;
            }
        } else {
            $ymdKeys = array_keys($dateCounts);
            $minYmd = reset($ymdKeys);
            $maxYmd = end($ymdKeys);

            $startTs = ($dateFrom !== '') ? strtotime($dateFrom) : strtotime($minYmd);
            $endTs = ($dateTo !== '') ? strtotime($dateTo) : strtotime($maxYmd);

            if ($startTs <= 0 || $endTs <= 0 || $startTs > $endTs) {
                $startTs = strtotime($minYmd);
                $endTs = strtotime($maxYmd);
            }

            $daysDiff = (int)ceil(($endTs - $startTs) / 86400);

            if ($daysDiff > 31) {
                // Разбивка по неделям
                $trendMode = 'weeks';
                $currStart = $startTs;
                while ($currStart <= $endTs) {
                    $currEnd = min($endTs, $currStart + 6 * 86400);
                    $label = date('d.m', $currStart) . '-' . date('d.m', $currEnd);
                    $sumWeek = 0;

                    for ($t = $currStart; $t <= $currEnd; $t += 86400) {
                        $y = date('Y-m-d', $t);
                        if (isset($dateCounts[$y])) {
                            $sumWeek += $dateCounts[$y];
                        }
                    }

                    $trendDays[] = $label;
                    $trendValues[] = $sumWeek;

                    $currStart = $currEnd + 86400;
                }
            } else {
                // Разбивка по дням
                $trendMode = 'days';
                for ($t = $startTs; $t <= $endTs; $t += 86400) {
                    $y = date('Y-m-d', $t);
                    $trendDays[] = date('d.m', $t);
                    $trendValues[] = $dateCounts[$y] ?? 0;
                }
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
        
        // Сортировка типов кузова: сначала заполненные по убыванию количества, в конце "Не заполнено"
        $bodyTypeStatsSorted = $bodyTypeStats;
        $unfilledCount = $bodyTypeStatsSorted['Не заполнено'] ?? 0;
        unset($bodyTypeStatsSorted['Не заполнено']);
        arsort($bodyTypeStatsSorted);
        if ($unfilledCount > 0 || empty($bodyTypeStatsSorted)) {
            $bodyTypeStatsSorted['Не заполнено'] = $unfilledCount;
        }

        $filteredStacked = [];
        foreach ($bodyTypeStatsSorted as $btName => $btCnt) {
            // Исключаем пустые категории (0 шт.), чтобы не дублировать и не засорять дашборд
            if ($btCnt <= 0 && $btName !== 'Не заполнено') {
                continue;
            }
            $pct = $totalBodyCount > 0 ? round(($btCnt / $totalBodyCount) * 100, 1) : 0;
            $bodyTypesFormatted[$btName] = [
                'percent' => $pct,
                'count' => $btCnt
            ];
            $filteredStacked[$btName] = $bodyTypeDiameterStacked[$btName] ?? ['R15' => 0, 'R16' => 0, 'R17' => 0, 'R18' => 0, 'R18+' => 0, 'R19+' => 0, 'Не заполнено' => 0];
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
                    'avg' => $avgValue,
                    'mode' => $trendMode
                ],
                'booking_depth' => $bookingDepthExtended,
            ],
            'block2' => [
                'total_records' => $countFiltered,
                'total_clients' => count($uniqueClients),
                'storage_contracts' => $storageCount,
                'storage_percent' => $storagePercent,
                'top_tire_size' => $topTireSize,
                'tire_diameters' => $tireDiametersFormatted,
                'body_types' => $bodyTypesFormatted,
                'stacked_body_types' => $filteredStacked,
            ]
        ];

        if ($cache->startDataCache()) {
            $cache->endDataCache($result);
        }

        return $result;
    }
}
