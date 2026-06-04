<?php
/**
 * Class AyyamulBidhCalculator
 * Menghitung tanggal puasa Ayyamul Bidh berdasarkan kalender Hijriyah
 */
class AyyamulBidhCalculator {
    private $adjustment = 0; // Kalibrasi kalender Hijriyah

    public function setAdjustment($days) {
        $this->adjustment = (int)$days;
    }

    public function getAdjustment() {
        return $this->adjustment;
    }

    private $hijriMonths = [
        1 => "Muharram",
        2 => "Safar",
        3 => "Rabi' al-Awwal",
        4 => "Rabi' al-Thani",
        5 => "Jumada al-Awwal",
        6 => "Jumada al-Thani",
        7 => "Rajab",
        8 => "Sha'ban",
        9 => "Ramadan",
        10 => "Shawwal",
        11 => "Dhu al-Qi'dah",
        12 => "Dhu al-Hijjah"
    ];

    /**
     * Konversi Gregorian ke Hijriyah menggunakan API dengan local caching dan fallback ke algoritma lokal
     */
    public function gregorianToHijri($date) {
        $dateTime = ($date instanceof DateTime) ? $date : new DateTime($date);
        $dateStr = $dateTime->format('Y-m-d');
        
        $apiResult = $this->fetchHijriFromApi($dateStr, $this->adjustment);
        if ($apiResult) {
            return $apiResult;
        }
        
        return $this->gregorianToHijriLegacy($dateTime);
    }

    /**
     * Helper to fetch Hijri date from API with file caching
     */
    private function fetchHijriFromApi($dateStr, $offset) {
        $cacheFile = __DIR__ . '/hijri_cache.json';
        $cacheKey = $dateStr . '_' . $offset;
        
        $cache = [];
        if (file_exists($cacheFile)) {
            $data = @file_get_contents($cacheFile);
            if ($data) {
                $cache = json_decode($data, true) ?: [];
            }
        }
        
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }
        
        $url = 'https://us-central1-al-waqt-9cdb7.cloudfunctions.net/getHijriCalendar?' . http_build_query([
            'date' => $dateStr,
            'method' => 'kemenag',
            'offset' => $offset
        ]);
        
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 5,
                'header' => "User-Agent: PHP-AyyamulBidhCalculator\r\n"
            ]
        ]);
        
        $responseJson = @file_get_contents($url, false, $ctx);
        if ($responseJson) {
            $response = json_decode($responseJson, true);
            if ($response && !empty($response['success']) && !empty($response['hijri'])) {
                $hijri = $response['hijri'];
                $result = [
                    'year' => (int)$hijri['year'],
                    'month' => (int)$hijri['month'],
                    'day' => (int)$hijri['day'],
                    'month_name' => $this->hijriMonths[(int)$hijri['month']] ?? $hijri['monthName']
                ];
                
                $cache[$cacheKey] = $result;
                @file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT));
                return $result;
            }
        }
        
        return null;
    }

    private function gregorianToHijriLegacy($date) {
        $gy = ($date instanceof DateTime) ? $date->format('Y') : date('Y', strtotime($date));
        $gm = ($date instanceof DateTime) ? $date->format('n') : date('n', strtotime($date));
        $gd = ($date instanceof DateTime) ? $date->format('j') : date('j', strtotime($date));

        $jd = $this->gregorianToJulian($gy, $gm, $gd) + $this->adjustment;
        $l = $jd - 1948440 + 10632;
        $n = (int)(($l - 1) / 10631);
        $l = $l - 10631 * $n + 354;
        $j = ((int)((10985 - $l) / 5316)) * ((int)((50 * $l) / 17719)) + ((int)($l / 5670)) * ((int)((43 * $l) / 15238));
        $l = $l - ((int)((30 - $j) / 15)) * ((int)((17719 * $j) / 50)) - ((int)($j / 16)) * ((int)((15238 * $j) / 43)) + 29;
        $m = (int)((24 * $l) / 709);
        $d = $l - (int)((709 * $m) / 24);
        $y = 30 * $n + $j - 30;

        return [
            'year' => $y,
            'month' => $m,
            'day' => $d,
            'month_name' => $this->hijriMonths[$m] ?? 'Unknown'
        ];
    }

    private function gregorianToJulian($year, $month, $day) {
        if ($month <= 2) {
            $year -= 1;
            $month += 12;
        }
        $a = (int)($year / 100);
        $b = (int)($a / 4);
        $c = 2 - $a + $b;
        $e = (int)(365.25 * ($year + 4716));
        $f = (int)(30.6001 * ($month + 1));
        return $c + $day + $e + $f - 1524.5;
    }

    /**
     * Dapatkan tanggal Ayyamul Bidh untuk bulan Hijriyah saat ini
     */
    public function getAyyamulBidhDates($currentDate = null) {
        $currentDate = $currentDate ?? new DateTime();
        $currentHijri = $this->gregorianToHijri($currentDate);

        // Tidak ada puasa Ayyamul Bidh di bulan Dzulhijjah
        if ($currentHijri['month'] == 12) {
            return [
                'error' => "Bulan Dzulhijjah - Puasa Ayyamul Bidh tidak disyariatkan",
                'dates' => []
            ];
        }

        $dates = [];
        for ($day = 13; $day <= 15; $day++) {
            $hijriDate = $this->getGregorianFromHijri(
                $currentHijri['year'],
                $currentHijri['month'],
                $day
            );

            if ($hijriDate) {
                $dates[] = [
                    'hijri' => [
                        'day' => $day,
                        'month' => $currentHijri['month'],
                        'month_name' => $currentHijri['month_name'],
                        'year' => $currentHijri['year']
                    ],
                    'gregorian' => $hijriDate,
                    'formatted' => $hijriDate->format('l, j F Y')
                ];
            }
        }

        return [
            'error' => null,
            'dates' => $dates,
            'current_hijri_month' => $currentHijri['month_name'],
            'current_hijri_year' => $currentHijri['year']
        ];
    }

    /**
     * Konversi Hijriyah ke Gregorian menggunakan pencarian rentang (±3 hari) berbasis API
     */
    public function getGregorianFromHijri($hy, $hm, $hd) {
        // 1. Dapatkan perkiraan tanggal menggunakan kalkulasi tabular lokal
        $approxJd = (int)((11 * $hy + 3) / 30) + (int)(354 * $hy) + (int)(30 * $hm) - (int)(($hm - 1) / 2) + $hd + 1948440 - 385 - $this->adjustment;
        
        $l = $approxJd + 68569;
        $n = (int)((4 * $l) / 146097);
        $l = $l - (int)((146097 * $n + 3) / 4);
        $i = (int)((4000 * ($l + 1)) / 1461001);
        $l = $l - (int)((1461 * $i) / 4) + 31;
        $j = (int)((80 * $l) / 2447);
        $d = $l - (int)((2447 * $j) / 80);
        $l = (int)($j / 11);
        $m = $j + 2 - 12 * $l;
        $y = 100 * ($n - 49) + $i + $l;
        
        $approxDate = DateTime::createFromFormat('Y-n-j', "$y-$m-$d");
        if (!$approxDate) {
            return false;
        }
        $approxDate->setTime(0, 0);
        
        // 2. Scan dalam rentang ±3 hari di sekitar perkiraan awal untuk kecocokan presisi via API
        for ($offsetDays = -3; $offsetDays <= 3; $offsetDays++) {
            $candidateDate = clone $approxDate;
            if ($offsetDays != 0) {
                $candidateDate->modify("$offsetDays days");
            }
            
            $hijri = $this->gregorianToHijri($candidateDate);
            if ($hijri && $hijri['year'] == $hy && $hijri['month'] == $hm && $hijri['day'] == $hd) {
                return $candidateDate;
            }
        }
        
        return $approxDate;
    }
}
?>