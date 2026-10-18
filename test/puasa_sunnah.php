<?php
// FILE: test/puasa_sunnah.php
//
// Logika gembok puasa sunnah (Senin/Kamis & Ayyamul Bidh).
// Dipakai bersama oleh:
//   - test/index.php     (tampilan tabel: sel yang bergembok)
//   - test/bi.php        (analisis: hari bergembok JANGAN ikut "possible")
//   - test/download.php  (unduhan teks: metrik & persen)
//
// Satu sumber kebenaran supaya hari bergembok tidak pernah dihitung sebagai
// "possible" di halaman mana pun. Hasil konversi Hijriah di-cache per tanggal
// karena BI memanggilnya untuk banyak peserta.

if (!function_exists('bi_is_senin_kamis')) {
    function bi_is_senin_kamis($dateStr)
    {
        $dayOfWeek = (int) date('N', strtotime((string) $dateStr)); // 1 Senin, 4 Kamis
        return ($dayOfWeek === 1 || $dayOfWeek === 4);
    }
}

if (!function_exists('bi_is_tasyrik')) {
    function bi_is_tasyrik($dateStr)
    {
        static $cache = [];
        $key = (string) $dateStr;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        require_once __DIR__ . '/AyamulBidhCalc.php';
        $calculator = new AyyamulBidhCalculator();
        $calculator->setAdjustment(0);

        $date = new DateTime($key);

        // Pre-check lokal secara cepat untuk menyaring tanggal yang tidak mungkin
        $localHijri = $calculator->gregorianToHijriLegacy($date);
        $isDzulhijjahCandidate = ($localHijri['month'] == 12 && $localHijri['day'] >= 8 && $localHijri['day'] <= 15);
        $isShawwalCandidate = ($localHijri['month'] == 10 && ($localHijri['day'] >= 29 || $localHijri['day'] <= 3));

        if (!$isDzulhijjahCandidate && !$isShawwalCandidate) {
            return $cache[$key] = false;
        }

        $hijri = $calculator->gregorianToHijri($date);

        // Idul Adha (10 Dzulhijjah) & Hari Tasyrik (11, 12, 13 Dzulhijjah)
        if ($hijri['month'] == 12 && in_array($hijri['day'], [10, 11, 12, 13], true)) {
            return $cache[$key] = true;
        }

        // Idul Fitri (1 Shawwal)
        if ($hijri['month'] == 10 && $hijri['day'] == 1) {
            return $cache[$key] = true;
        }

        return $cache[$key] = false;
    }
}

if (!function_exists('bi_is_ayyamul_bidh')) {
    function bi_is_ayyamul_bidh($dateStr)
    {
        static $cache = [];
        $key = (string) $dateStr;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        require_once __DIR__ . '/AyamulBidhCalc.php';
        $calculator = new AyyamulBidhCalculator();
        $calculator->setAdjustment(0);

        $date = new DateTime($key);

        // Pre-check lokal secara cepat untuk menyaring tanggal yang tidak mungkin
        $localHijri = $calculator->gregorianToHijriLegacy($date);
        if ($localHijri['month'] == 12) {
            return $cache[$key] = false;
        }
        if ($localHijri['day'] < 11 || $localHijri['day'] > 17) {
            return $cache[$key] = false;
        }

        $hijri = $calculator->gregorianToHijri($date);

        // Pastikan bukan bulan Dzulhijjah (12)
        if ($hijri['month'] == 12) {
            return $cache[$key] = false;
        }

        return $cache[$key] = ($hijri['day'] == 13 || $hijri['day'] == 14 || $hijri['day'] == 15);
    }
}

if (!function_exists('bi_puasa_key_locked')) {
    // Apakah sel kunci puasa tertentu sedang bergembok pada tanggal ini?
    // $dateStr format "YYYY-MM-DD". Kunci non-puasa -> selalu false.
    function bi_puasa_key_locked(string $key, string $dateStr): bool
    {
        if ($key === 'senin_kamis') {
            return (!bi_is_senin_kamis($dateStr) || bi_is_tasyrik($dateStr));
        }
        if ($key === 'ayamul_bidh') {
            return !bi_is_ayyamul_bidh($dateStr);
        }

        return false;
    }
}
