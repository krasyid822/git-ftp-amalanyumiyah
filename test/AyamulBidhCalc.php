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
     * Konversi Gregorian ke Hijriyah menggunakan algoritma Kuwaiti
     */
    public function gregorianToHijri($date) {
        // PERBAIKAN: Menambahkan ')' sebelum '{'
        if (version_compare(PHP_VERSION, '8.0.0')) {
            return $this->gregorianToHijriModern($date);
        }
        return $this->gregorianToHijriLegacy($date);
    }

    private function gregorianToHijriModern($date) {
        $gy = $date->format('Y');
        $gm = $date->format('n');
        $gd = $date->format('j');

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

    private function gregorianToHijriLegacy($date) {
        // Catatan: strtotime($date) akan gagal jika $date adalah objek DateTime
        // Sebaiknya konsisten menggunakan objek DateTime
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
     * Konversi Hijriyah ke Gregorian (pendekatan)
     */
    private function getGregorianFromHijri($hy, $hm, $hd) {
        // Ini adalah pendekatan sederhana, untuk implementasi lebih akurat
        // sebaiknya menggunakan library khusus atau API
        $jd = (int)((11 * $hy + 3) / 30) + (int)(354 * $hy) + (int)(30 * $hm) - (int)(($hm - 1) / 2) + $hd + 1948440 - 385 - $this->adjustment;
        
        $l = $jd + 68569;
        $n = (int)((4 * $l) / 146097);
        $l = $l - (int)((146097 * $n + 3) / 4);
        $i = (int)((4000 * ($l + 1)) / 1461001);
        $l = $l - (int)((1461 * $i) / 4) + 31;
        $j = (int)((80 * $l) / 2447);
        $d = $l - (int)((2447 * $j) / 80);
        $l = (int)($j / 11);
        $m = $j + 2 - 12 * $l;
        $y = 100 * ($n - 49) + $i + $l;
        
        // Menambahkan setTime(0, 0) untuk menghindari masalah timezone
        $date = DateTime::createFromFormat('Y-n-j', "$y-$m-$d");
        return $date ? $date->setTime(0, 0) : false;
    }
}
?>