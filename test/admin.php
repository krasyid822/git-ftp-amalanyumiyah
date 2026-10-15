<?php
// FILE: test/admin.php
// Halaman admin untuk mengelola grup mentoring dan mentee.
//
// Aturan struktur folder:
//   <root>/<grup>/index.php          -> halaman grup (wajib ada)
//   <root>/<grup>/target_settings.json
//   <root>/<grup>/<mentee>/index.php -> halaman mentee
//   <root>/<grup>/<mentee>/download.php
//   <root>/<grup>/<mentee>/.THIS_IS_MENTOR -> tandai mentor
//   <root>/.DEPRECATED/<grup>/<mentee>/     -> tempat sampah (bisa dipulihkan)
//
// Manifest PWA:
//   Repo khusus manifest, dipindah dari repo Drive:
//     https://github.com/krasyid822/for-manifest-json-pwa
//   Aplikasi membaca lewat raw.githubusercontent.com. Admin.php menulis
//   dan membaca repo itu langsung lewat GitHub REST API (butuh token).
//
// Keamanan token:
//   Disimpan di test/_manifest/.token (mode 600). File ini berada di
//   .gitignore sehingga TIDAK ikut ke GitHub. Token tetap boleh ada di
//   server FTP (ditulis langsung oleh admin.php saat Anda menempelnya
//   di halaman admin), jadi tidak perlu di-push manual.

date_default_timezone_set('Asia/Jakarta');

const ADMIN_ROOT   = __DIR__ . '/..';
const TRASH_DIR    = '.DEPRECATED';
const OUT_DIR      = '.OUT'; // di dalam .DEPRECATED: mentee yang tidak lagi mentoring
const MENTOR_FLAG  = '.THIS_IS_MENTOR';
const RESERVED_DIRS = ['test', TRASH_DIR, '.git', 'assets'];

// --- Konfigurasi manifest PWA ---
const MANIFEST_REPO   = 'krasyid822/for-manifest-json-pwa';
const MANIFEST_BRANCH = 'main';
const SITE_ORIGIN     = 'https://amalanyaumiyah.xo.je';
const ICON_BASE       = 'https://raw.githubusercontent.com/krasyid822/for-manifest-json-pwa/main/icons';
const MANIFEST_LOCAL_DIR = __DIR__ . '/_manifest';
const GITHUB_TOKEN_FILE  = MANIFEST_LOCAL_DIR . '/.token';
const GITHUB_USER_AGENT = 'amalanyaumiyah-admin';

// --- Revisi aset tampilan (cache-busting) ---
// Versi aset tema (ikon & tampilan). Nilai ini dipakai pada tautan ikon agar
// perangkat memuat ulang aset saat tampilan berubah; boleh diperbarui kapan saja.
const ADM_ASSET_REV = '8c7d4a5bd6a791247fc16aad88d44374a8e0d87b';

function adm_h(mixed $v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

// ======================= GITHUB API =======================

/** Ambil token PAT dari file. Kembalikan string kosong bila belum diisi. */
function adm_gh_token(): string {
    if (!is_file(GITHUB_TOKEN_FILE)) return '';
    return trim((string) @file_get_contents(GITHUB_TOKEN_FILE));
}

function adm_gh_token_set(string $token): array {
    if (!is_dir(MANIFEST_LOCAL_DIR)) mkdir(MANIFEST_LOCAL_DIR, 0755, true);
    $token = trim($token);

    if ($token === '') {
        if (is_file(GITHUB_TOKEN_FILE)) @unlink(GITHUB_TOKEN_FILE);
        return ['ok' => true, 'msg' => 'Token dihapus.'];
    }
    if (!preg_match('/^gh[pousr]_[A-Za-z0-9]{20,255}$/', $token)) {
        return ['ok' => false, 'msg' => 'Format token tidak dikenali.'];
    }
    if (@file_put_contents(GITHUB_TOKEN_FILE, $token . "\n") === false) {
        return ['ok' => false, 'msg' => 'Gagal menulis file token. Periksa hak akses folder.'];
    }
    @chmod(GITHUB_TOKEN_FILE, 0600);

    // Verifikasi token benar-benar bekerja sebelum disimpan permanen.
    $me = adm_gh_api('GET', 'https://api.github.com/user');
    if (!$me['ok']) {
        @unlink(GITHUB_TOKEN_FILE);
        return ['ok' => false, 'msg' => 'Token ditolak GitHub: ' . $me['error']];
    }
    return ['ok' => true, 'msg' => "Token tersimpan & terverifikasi sebagai @{$me['data']['login']}."];
}

/**
 * Panggil GitHub REST API.
 * @return array{ok:bool, data?:mixed, error?:string, code?:int}
 */
function adm_gh_api(string $method, string $url, ?array $body = null): array {
    $token = adm_gh_token();
    if ($token === '') {
        return ['ok' => false, 'error' => 'Token GitHub belum diisi.', 'code' => 401];
    }

    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/vnd.github+json',
        'X-GitHub-Api-Version: 2022-11-28',
        'User-Agent: ' . GITHUB_USER_AGENT,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES));
    }

    $res  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($res === false) {
        return ['ok' => false, 'error' => 'Gagal koneksi: ' . $err, 'code' => 0];
    }

    $data = json_decode((string) $res, true);

    if ($code < 200 || $code >= 300) {
        $msg = $data['message'] ?? ('HTTP ' . $code);
        if ($code === 401)      $msg = 'Token ditolak atau kedaluwarsa.';
        elseif ($code === 403)  $msg = 'Akses ditolak. Cek scopes token (butuh "contents").';
        elseif ($code === 404)  $msg = 'Repo/file tidak ditemukan. Cek repo & branch.';
        elseif ($code === 409)  $msg = 'Konflik SHA - file berubah, muat ulang lalu coba lagi.';
        return ['ok' => false, 'error' => $msg, 'code' => $code];
    }

    return ['ok' => true, 'data' => $data, 'code' => $code];
}

/** Daftar file di root repo manifest pada branch tertentu. */
function adm_gh_list(): array {
    $url = 'https://api.github.com/repos/' . MANIFEST_REPO . '/contents/?ref=' . rawurlencode(MANIFEST_BRANCH);
    $r = adm_gh_api('GET', $url);
    if (!$r['ok']) return $r;
    if (!is_array($r['data'])) return ['ok' => false, 'error' => 'Respons tidak terduga.'];

    $files = [];
    foreach ($r['data'] as $f) {
        if (($f['type'] ?? '') !== 'file') continue;
        if (!preg_match('/^manifest_[A-Za-z0-9_-]+\.json$/', $f['name'] ?? '')) continue;
        $files[] = [
            'name'  => $f['name'],
            'sha'   => $f['sha'],
            'size'  => (int) ($f['size'] ?? 0),
            'url'   => adm_manifest_raw_url(preg_replace('/^manifest_|\.json$/', '', $f['name'])),
        ];
    }
    usort($files, fn($a, $b) => strcmp($a['name'], $b['name']));
    return ['ok' => true, 'data' => $files];
}

/** Ambil isi file dari repo (bukan raw, supaya ada SHA untuk commit). */
function adm_gh_get(string $filename): array {
    $url = 'https://api.github.com/repos/' . MANIFEST_REPO . '/contents/' . rawurlencode($filename)
         . '?ref=' . rawurlencode(MANIFEST_BRANCH);
    return adm_gh_api('GET', $url);
}

/**
 * Tulis (atau perbarui) satu file di repo manifest.
 * Membaca SHA lama dulu bila file sudah ada, agar jadi update bukan create.
 */
function adm_gh_put(string $filename, string $content, string $message): array {
    if (!preg_match('/^manifest_[A-Za-z0-9_-]+\.json$/', $filename)) {
        return ['ok' => false, 'error' => 'Nama file manifest tidak valid.'];
    }

    $payload = [
        'message' => $message,
        'content' => base64_encode($content),
        'branch'  => MANIFEST_BRANCH,
    ];

    // Kalau file sudah ada, sertakan SHA-nya supaya GitHub memperbarui.
    $existing = adm_gh_get($filename);
    if ($existing['ok'] && isset($existing['data']['sha'])) {
        $payload['sha'] = $existing['data']['sha'];
    }

    $url = 'https://api.github.com/repos/' . MANIFEST_REPO . '/contents/' . rawurlencode($filename);
    return adm_gh_api('PUT', $url, $payload);
}

/** Hapus file dari repo manifest. */
function adm_gh_delete(string $filename, string $message): array {
    $existing = adm_gh_get($filename);
    if (!$existing['ok']) {
        return ['ok' => false, 'error' => $existing['error'] ?? 'Gagal membaca file.'];
    }
    $payload = [
        'message' => $message,
        'sha'     => $existing['data']['sha'],
        'branch'  => MANIFEST_BRANCH,
    ];
    $url = 'https://api.github.com/repos/' . MANIFEST_REPO . '/contents/' . rawurlencode($filename);
    return adm_gh_api('DELETE', $url, $payload);
}

/** Nama folder valid: huruf, angka, _ dan - saja. Mencegah path traversal. */
function adm_valid_name(string $name): bool {
    return (bool) $name && strlen($name) <= 64 && preg_match('/^[A-Za-z0-9_-]+$/', $name);
}

/**
 * Ubah input user menjadi nama folder yang aman.
 * Menolak apa pun yang mengandung pemisah path, alih-alih
 * diam-diam memotongnya jadi nama lain (mis. "../etc" -> "etc").
 */
function adm_slug(string $name): string {
    $name = trim($name);
    if ($name === '') return '';
    if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')) {
        return '';
    }
    $slug = strtolower(str_replace(' ', '_', $name));
    return preg_replace('/[^a-z0-9_-]/', '', $slug) ?? '';
}

/** Daftar folder di dalam $dir, tanpa file tersembunyi. */
function adm_subfolders(string $dir): array {
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        if (str_starts_with($e, '.')) continue;
        if (is_dir($dir . '/' . $e)) $out[] = $e;
    }
    sort($out);
    return $out;
}

/** Folder di root yang dianggap grup mentoring (selain yang dikecualikan). */
function adm_groups(string $root): array {
    return array_values(array_diff(
        adm_subfolders($root),
        array_filter(RESERVED_DIRS, fn($d) => strcasecmp($d, TRASH_DIR) !== 0)
    ));
}

/** Mentee dalam sebuah grup, dengan info mentor. */
function adm_mentees(string $groupDir): array {
    $out = [];
    foreach (adm_subfolders($groupDir) as $m) {
        $dir = $groupDir . '/' . $m;
        $out[$m] = [
            'name'    => $m,
            'isMentor'=> is_file($dir . '/' . MENTOR_FLAG),
            'hasIndex'=> is_file($dir . '/index.php'),
            'hasJson' => is_file($dir . '/data_amalan.json'),
            'days'    => adm_days_count($dir . '/data_amalan.json'),
        ];
    }
    return $out;
}

/** Jumlah hari yang punya data, untuk display ringkas. */
function adm_days_count(string $jsonFile): int {
    if (!is_file($jsonFile)) return 0;
    $data = json_decode((string) @file_get_contents($jsonFile), true);
    if (!is_array($data)) return 0;
    $days = [];
    foreach ($data as $bulan => $keys) {
        if ($bulan === '__metadata' || !is_array($keys)) continue;
        foreach ($keys as $key => $vals) {
            if (!is_array($vals)) continue;
            foreach ($vals as $d => $v) {
                if ($v !== '' && $v !== null) $days[$bulan . '-' . str_pad((string) $d, 2, '0', STR_PAD_LEFT)] = 1;
            }
        }
    }
    return count($days);
}

/**
 * Cari mentee dengan nama sama di grup aktif mana pun.
 * .DEPRECATED sengaja tidak ikut dicari di sini supaya hasil pencarian
 * bisa dibedakan antara "aktif" dan "di tempat sampah".
 */
function adm_find_active_mentee(string $root, string $name, ?string $exceptGroup = null): ?array {
    foreach (adm_groups($root) as $g) {
        if ($exceptGroup !== null && $g === $exceptGroup) continue;
        $dir = $root . '/' . $g . '/' . $name;
        if (is_dir($dir)) return ['group' => $g, 'path' => $dir];
    }
    return null;
}

/**
 * Cari mentee di tempat sampah: <root>/.DEPRECATED/<grup>/<mentee>/.
 * Dipakai untuk pemulihan otomatis supaya data lama tidak hilang.
 */
function adm_find_trashed_mentee(string $root, string $name, ?string $exceptGroup = null): ?array {
    $trash = $root . '/' . TRASH_DIR;
    foreach (adm_subfolders($trash) as $g) {
        if ($exceptGroup !== null && $g === $exceptGroup) continue;
        $dir = $trash . '/' . $g . '/' . $name;
        if (is_dir($dir)) return ['group' => $g, 'path' => $dir];
    }
    return null;
}

/**
 * Pindahkan folder $src ke $dest. Bila $dest sudah ada, isi $src digabungkan ke
 * dalam $dest (tanpa menimpa) lalu $src dihapus. Dipakai saat menghapus grup utuh
 * agar strukturnya tetap seragam: <root>/.DEPRECATED/<grup>/<mentee>/.
 */
function adm_move_merge(string $src, string $dest): bool {
    if (!is_dir($dest)) return @rename($src, $dest);

    $ok = true;
    foreach (scandir($src) ?: [] as $e) {
        if ($e === '.' || $e === '..') continue;
        $from = $src . '/' . $e;
        $to   = $dest . '/' . $e;
        if (file_exists($to)) $to .= '-' . date('Ymd-His');
        if (!@rename($from, $to)) $ok = false;
    }
    @rmdir($src); // hanya terhapus bila benar-benar sudah kosong
    return $ok && !is_dir($src);
}

function adm_json_out(array $payload): never {
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function adm_msg(string $text, string $type = 'ok'): array {
    return ['text' => $text, 'type' => $type];
}

// ======================= MANIFEST PWA =======================

/** Nama file manifest untuk sebuah mentee: manifest_<nama>.json */
function adm_manifest_filename(string $mentee): string {
    return 'manifest_' . $mentee . '.json';
}

/** URL raw GitHub untuk dibaca aplikasi di browser. */
function adm_manifest_raw_url(string $mentee): string {
    return 'https://raw.githubusercontent.com/' . MANIFEST_REPO
         . '/' . MANIFEST_BRANCH . '/' . adm_manifest_filename($mentee);
}

/** Nama file manifest untuk halaman dasbor grup: manifest_<grup>.json */
function adm_group_manifest_filename(string $group): string {
    return 'manifest_' . $group . '.json';
}

/** URL raw GitHub manifest grup. */
function adm_group_manifest_raw_url(string $group): string {
    return 'https://raw.githubusercontent.com/' . MANIFEST_REPO
         . '/' . MANIFEST_BRANCH . '/' . adm_group_manifest_filename($group);
}

/**
 * Bangun manifest PWA untuk halaman dasbor grup (halaman analisis).
 * start_url & scope menunjuk ke folder grup, bukan folder mentee.
 */
function adm_build_group_manifest(string $group): array {
    $up        = strtoupper(str_replace(['_', '-'], ' ', $group));
    $groupSlug = rawurlencode($group);
    $base      = SITE_ORIGIN . '/' . $groupSlug . '/';

    return [
        'name'             => 'Dashboard Grup ' . $up,
        'short_name'       => 'Grup ' . $up,
        'description'      => 'Analisis amalan seluruh anggota grup ' . $up . '.',
        'lang'             => 'id-ID',
        'dir'              => 'ltr',
        'display'          => 'standalone',
        'background_color' => '#FFFFFF',
        'theme_color'      => '#2E8B57',
        'orientation'      => 'portrait-primary',
        'scope'            => $base,
        'start_url'        => $base . 'index.php?utm_source=pwa',
        'id'               => $base,
        'icons' => [
            ['src' => ICON_BASE . '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => ICON_BASE . '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => ICON_BASE . '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ];
}

/**
 * Bangun isi manifest PWA untuk satu mentee.
 *
 * start_url dan scope menunjuk ke folder mentee supaya setiap orang
 * punya PWA terpisah dengan nama sendiri, walau aplikasinya sama.
 */
function adm_build_manifest(string $mentee, string $group, bool $isMentor): array {
    $slug  = rawurlencode($mentee);
    $groupSlug = rawurlencode($group);
    $base  = SITE_ORIGIN . '/' . $groupSlug . '/' . $slug . '/';

    $display = adm_format_name($mentee);

    $manifest = [
        'name'             => 'Amal Yaumiyah - ' . $display,
        'short_name'       => $display,
        'description'      => 'Catat dan pantau ibadah harianmu untuk menjadi lebih istiqomah.',
        'lang'             => 'id-ID',
        'dir'              => 'ltr',
        'display'          => 'standalone',
        'background_color' => '#FFFFFF',
        'theme_color'      => '#2E8B57',
        'orientation'      => 'portrait-primary',
        'scope'            => $base,
        'start_url'        => $base . 'index.php?utm_source=pwa',
        'id'               => $base,
        'icons' => [
            ['src' => ICON_BASE . '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => ICON_BASE . '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => ICON_BASE . '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
        'shortcuts' => [
            [
                'name'        => 'Download Laporan Bulan Ini',
                'short_name'  => 'Download Laporan',
                'description' => 'Unduh laporan amalan dalam CSV',
                'url'         => $base . 'download.php',
            ],
        ],
    ];

    if ($isMentor) {
        $manifest['shortcuts'][] = [
            'name'        => 'Dashboard Grup ' . strtoupper($group),
            'short_name'  => 'Dashboard Grup',
            'description' => 'Lihat analisis seluruh kelompok',
            'url'         => SITE_ORIGIN . '/' . $groupSlug . '/index.php',
        ];
    }

    return $manifest;
}

/** "budi_santoso" -> "Budi Santoso" */
function adm_format_name(string $slug): string {
    return ucwords(str_replace(['_', '-'], ' ', $slug));
}

/** Tulis manifest ke folder lokal test/_manifest/. */
function adm_write_manifest(string $mentee, string $group, bool $isMentor): array {
    if (!is_dir(MANIFEST_LOCAL_DIR)) mkdir(MANIFEST_LOCAL_DIR, 0755, true);

    $manifest = adm_build_manifest($mentee, $group, $isMentor);
    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['ok' => false, 'error' => 'Gagal encodes JSON manifest.'];
    }

    $file = MANIFEST_LOCAL_DIR . '/' . adm_manifest_filename($mentee);
    if (@file_put_contents($file, $json . "\n") === false) {
        return ['ok' => false, 'error' => "Gagal menulis $file. Periksa hak akses folder."];
    }

    return ['ok' => true, 'file' => $file, 'url' => adm_manifest_raw_url($mentee), 'json' => $json . "\n"];
}

/**
 * Tulis manifest mentee ke folder lokal, terbitkan ke repo (bila token ada),
 * lalu arahkan index.php-nya ke raw URL. Mengembalikan daftar pesan.
 * Dipakai saat mentee dibuat/dipulihkan supaya tidak memakai manifest placeholder.
 */
function adm_publish_mentee_manifest(string $root, string $group, string $mentee, bool $isMentor): array {
    $msgs = [];
    $res  = adm_write_manifest($mentee, $group, $isMentor);
    if (!$res['ok']) {
        return [adm_msg($res['error'], 'err')];
    }

    if (adm_gh_token() !== '') {
        $pr = adm_gh_put(adm_manifest_filename($mentee), $res['json'], "manifest: $group/$mentee");
        $msgs[] = adm_msg(
            $pr['ok']
                ? "Manifest '$mentee' diterbitkan ke repo."
                : "Manifest lokal dibuat, tapi gagal ke repo: {$pr['error']}",
            $pr['ok'] ? 'ok' : 'err'
        );
    } else {
        $msgs[] = adm_msg("Manifest '$mentee' dibuat lokal. Terbitkan lewat 'Tulis ke GitHub'.", 'ok');
    }

    $idx = $root . '/' . $group . '/' . $mentee . '/index.php';
    $msgs[] = adm_write_manifest_path($idx, adm_manifest_raw_url($mentee))
        ? adm_msg("index.php '$mentee' diarahkan ke manifest raw.", 'ok')
        : adm_msg("Gagal menulis index.php '$mentee'.", 'err');

    return $msgs;
}

/** Daftar file manifest yang sudah ada di folder lokal. */
function adm_local_manifests(): array {
    if (!is_dir(MANIFEST_LOCAL_DIR)) return [];
    $out = [];
    foreach (scandir(MANIFEST_LOCAL_DIR) ?: [] as $e) {
        if (preg_match('/^manifest_[A-Za-z0-9_-]+\.json$/', $e)) {
            $out[] = ['file' => $e, 'size' => (int) @filesize(MANIFEST_LOCAL_DIR . '/' . $e)];
        }
    }
    sort($out);
    return $out;
}

/**
 * Tuliskan/ubah baris $manifestPath di sebuah file index.php.
 * Mengganti nilai lama apa pun (termasuk placeholder). Bila baris belum ada,
 * disisipkan tepat setelah tag <?php.
 */
function adm_write_manifest_path(string $file, string $url): bool {
    if (!is_file($file)) return false;
    $src = (string) @file_get_contents($file);
    if ($src === '') return false;

    $line = '$manifestPath = ' . var_export($url, true) . ';';
    $new  = preg_replace('/\$manifestPath\s*=\s*[\'"][^\'"]*[\'"]\s*;/', $line, $src, 1, $n);

    if ($new === null) return false;
    if ($n === 0) {
        // Belum ada baris $manifestPath -> sisipkan setelah <?php.
        $new = preg_replace('/(<\?php\s*\R)/', '$1' . $line . "\n", $src, 1, $n2);
        if ($new === null || $n2 === 0) return false;
    }
    if ($new === $src) return true; // sudah sama

    return @file_put_contents($file, $new) !== false;
}

/** Tulis salinan lokal manifest grup ke test/_manifest/. */
function adm_write_group_manifest(string $group): array {
    if (!is_dir(MANIFEST_LOCAL_DIR)) mkdir(MANIFEST_LOCAL_DIR, 0755, true);

    $json = json_encode(adm_build_group_manifest($group), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['ok' => false, 'error' => 'Gagal encodes JSON manifest grup.'];
    }

    $file = MANIFEST_LOCAL_DIR . '/' . adm_group_manifest_filename($group);
    if (@file_put_contents($file, $json . "\n") === false) {
        return ['ok' => false, 'error' => "Gagal menulis $file. Periksa hak akses folder."];
    }

    return ['ok' => true, 'file' => $file, 'url' => adm_group_manifest_raw_url($group), 'json' => $json . "\n"];
}

// ======================= TARGET SETTINGS (target_settings.json) =======================
// Konfigurasi target per grup, dibaca test/bi.php untuk menghitung skor analisis.
// Ditulis admin.php agar tidak lagi bergantung pada file manual yang ter-ignore.

/** Path file target settings sebuah grup. */
function adm_target_file(string $root, string $group): string {
    return $root . '/' . $group . '/target_settings.json';
}

/** Baca target_settings.json sebuah grup. Kembalikan [] bila tidak ada/rusak. */
function adm_read_targets(string $root, string $group): array {
    $file = adm_target_file($root, $group);
    if (!is_file($file)) return [];
    $data = json_decode((string) @file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

/** Encode data target untuk ditempel di <script type="application/json">. */
function adm_targets_json(array $data): string {
    $json = json_encode(
        $data,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    return $json === false ? '{}' : $json;
}

/** Tulis data target ke file. */
function adm_write_targets(string $file, array $data): array {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return ['ok' => false, 'error' => 'Gagal meng-encode JSON target.'];
    }
    if (@file_put_contents($file, $json . "\n") === false) {
        return ['ok' => false, 'error' => "Gagal menulis $file. Periksa hak akses folder."];
    }
    return ['ok' => true];
}

/**
 * Bersihkan data dari editor sebelum ditulis: hanya key yang dikenal yang
 * disimpan, agar file tidak diisi sampah. Urutan key dibuat rapi.
 */
function adm_normalize_targets(array $raw, string $group): array {
    $str = static fn($v, string $d = ''): string => is_scalar($v) ? trim((string) $v) : $d;
    $num = static function ($v, float $d = 0.0): float {
        return is_numeric($v) ? (float) $v : $d;
    };

    $out = [
        'group'    => strtoupper($str($raw['group'] ?? '', strtoupper($group))),
        'title'    => $str($raw['title'] ?? ''),
        'greeting' => $str($raw['greeting'] ?? ''),
        'intro'    => $str($raw['intro'] ?? ''),
        'closing'  => $str($raw['closing'] ?? ''),
        'tag'      => $str($raw['tag'] ?? ''),
        'rules'    => [],
    ];

    $validModes   = ['day_presence', 'value_count', 'filled_key_count', 'rawatib_count', 'numeric', 'quran_sheet_count'];
    $validPeriods = ['day', 'week', 'month'];

    foreach ((array) ($raw['rules'] ?? []) as $rule) {
        if (!is_array($rule)) continue;

        $mode = strtolower($str($rule['mode'] ?? 'day_presence'));
        if (!in_array($mode, $validModes, true)) $mode = 'day_presence';

        $period = strtolower($str($rule['period'] ?? 'day'));
        if (!in_array($period, $validPeriods, true)) $period = 'day';

        $keys = [];
        foreach ((array) ($rule['data_keys'] ?? []) as $k) {
            $k = $str($k);
            if ($k !== '') $keys[] = $k;
        }

        $clean = [
            'category'     => $str($rule['category'] ?? ''),
            'label'        => $str($rule['label'] ?? ''),
            'target'       => $str($rule['target'] ?? ''),
            'period'       => $period,
            'target_count' => $num($rule['target_count'] ?? 1, 1.0),
            'mode'         => $mode,
        ];

        if ($mode === 'value_count') {
            $clean['match_value'] = $str($rule['match_value'] ?? '');
        } elseif ($mode === 'rawatib_count') {
            $clean['rakaat_per_check'] = $num($rule['rakaat_per_check'] ?? 2, 2.0);
        } elseif ($mode === 'numeric') {
            $clean['threshold'] = $num($rule['threshold'] ?? ($rule['target_count'] ?? 1), 1.0);
        } elseif ($mode === 'quran_sheet_count') {
            $clean['pages_per_sheet'] = $num($rule['pages_per_sheet'] ?? 2, 2.0);
            $dataset = $str($rule['source_dataset'] ?? '');
            if ($dataset !== '') $clean['source_dataset'] = $dataset;
        } elseif ($mode === 'day_presence' || $mode === 'filled_key_count') {
            $clean['logic'] = strtolower($str($rule['logic'] ?? 'all')) === 'any' ? 'any' : 'all';
        }

        $clean['detail']     = $str($rule['detail'] ?? '');
        $clean['data_keys']  = $keys;

        // Buang aturan yang benar-benar kosong.
        if ($clean['label'] === '' && $clean['category'] === '' && !$keys) continue;

        $out['rules'][] = $clean;
    }

    return $out;
}

// ======================= AUTENTIKASI ADMIN =======================
// Kredensial dibuat oleh ./create-admin.sh dan disimpan terenkripsi (AES-256-GCM)
// di test/admin.json. File itu TIDAK ikut ke GitHub, tetapi ikut ke server FTP
// (lihat .git-ftp-include).
//
// Agar menyalin test/admin.json saja tidak cukup, kunci untuk membukanya
// tidak ditulis apa adanya di file itu: kuncinya dibungkus dengan bahan
// internal (lihat ADM_ASSET_REV) yang dipakai halaman ini.

const ADMIN_CRED_FILE = __DIR__ . '/admin.json';
const ADMIN_SESSION_USER = 'adm_user';
/** Penanda sidik jari admin.json saat sesi dibuat (lihat adm_logged_in()). */
const ADMIN_SESSION_CRED = 'adm_cred';

/**
 * Bahan kunci internal (32 byte) untuk membuka kunci kredensial di
 * test/admin.json. Diturunkan dari ADM_ASSET_REV.
 */
function adm_cred_secret(): string {
    return hash('sha256', ADM_ASSET_REV . '|v1', true);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'path'     => dirname($_SERVER['SCRIPT_NAME'] ?? '/test/admin.php'),
    ]);
    session_start();
}

/** Baca & dekripsi kredensial dari test/admin.json. Null bila tidak ada/rusak. */
function adm_cred_read(): ?array {
    if (!is_file(ADMIN_CRED_FILE)) return null;
    $j = json_decode((string) @file_get_contents(ADMIN_CRED_FILE), true);
    if (!is_array($j) || ($j['alg'] ?? '') !== 'aes-256-gcm') return null;

    $iv  = base64_decode((string) ($j['iv']      ?? ''), true);
    $tag = base64_decode((string) ($j['tag']     ?? ''), true);
    $ct  = base64_decode((string) ($j['data']    ?? ''), true);
    $kw  = base64_decode((string) ($j['key']     ?? ''), true);
    $kiv = base64_decode((string) ($j['key_iv']  ?? ''), true);
    $ktg = base64_decode((string) ($j['key_tag'] ?? ''), true);
    if ($iv === false || $tag === false || $ct === false || $kw === false || $kw === ''
        || $kiv === false || $ktg === false) {
        return null;
    }

    // Kunci kredensial tersimpan terbungkus; buka dengan bahan internal.
    $key = openssl_decrypt($kw, 'aes-256-gcm', adm_cred_secret(), OPENSSL_RAW_DATA, $kiv, $ktg);
    if ($key === false) return null;

    $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) return null;

    $data = json_decode($plain, true);
    return is_array($data) ? $data : null;
}

/** Cocokkan username & password dengan kredensial tersimpan. */
function adm_cred_verify(string $user, string $pass): bool {
    $data = adm_cred_read();
    if ($data === null) return false;
    return hash_equals((string) ($data['username'] ?? ''), $user)
        && password_verify($pass, (string) ($data['password_hash'] ?? ''));
}

/**
 * Sidik jari file kredensial. Berubah (atau null) bila admin.json dihapus,
 * rusak, atau dibuat ulang — dipakai untuk menggugurkan sesi lama.
 */
function adm_cred_fingerprint(): ?string {
    if (!is_file(ADMIN_CRED_FILE)) return null;
    $h = @hash_file('sha256', ADMIN_CRED_FILE);
    return $h === false ? null : $h;
}

/**
 * Sudah masuk? Sesi hanya berlaku selama file kredensial masih ada dan belum
 * berubah. Jadi menghapus/mengganti test/admin.json otomatis mengeluarkan admin
 * yang masih memegang sesi lama.
 */
function adm_logged_in(): bool {
    if (empty($_SESSION[ADMIN_SESSION_USER])) return false;
    $now = adm_cred_fingerprint();
    $was = (string) ($_SESSION[ADMIN_SESSION_CRED] ?? '');
    if ($now === null || $was === '' || !hash_equals($was, $now)) {
        $_SESSION = [];
        return false;
    }
    return true;
}

/** URL halaman ini tanpa query string (untuk redirect). */
function adm_self_url(): string {
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? 'admin.php');
    $q   = strpos($uri, '?');
    return $q === false ? $uri : substr($uri, 0, $q);
}

/** Halaman login admin (tampilan mandiri, tema gelap). Selalu keluar. */
function adm_render_login(string $error = '', bool $noCred = false): never {
    ?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Masuk Admin</title>
<link rel="icon" href="<?= adm_h(ICON_BASE) ?>/icon-192.png?rev=<?= adm_h(substr(ADM_ASSET_REV, 0, 12)) ?>">
<style>
:root{--bg:#061612;--surface:#0b1f1b;--line:rgba(158,243,208,.14);--line2:rgba(158,243,208,.22);
      --text:#eefbf5;--muted:#97b2a8;--accent:#7df0c5;--accent2:#ffd27d;--danger:#ff8b8b}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
     font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);padding:24px}
.card{width:100%;max-width:380px;background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:26px}
h1{font-size:1.25rem;margin:0 0 4px}
.sub{color:var(--muted);font-size:.85rem;margin:0 0 18px}
label{display:block;font-size:.72rem;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin:14px 0 6px}
input{width:100%;background:#08130f;border:1px solid var(--line2);color:var(--text);border-radius:9px;padding:11px 12px;font:inherit}
input:focus{outline:2px solid var(--accent);outline-offset:1px}
button{width:100%;margin-top:20px;font:inherit;font-weight:700;border:0;border-radius:9px;padding:11px 14px;
       cursor:pointer;background:var(--accent);color:#041611}
button:hover{filter:brightness(1.08)}
.err{background:rgba(255,139,139,.1);border:1px solid rgba(255,139,139,.3);color:var(--danger);
     border-radius:9px;padding:9px 12px;font-size:.85rem;margin-bottom:6px}
.warn{background:rgba(255,210,125,.1);border:1px solid rgba(255,210,125,.3);color:var(--accent2);
      border-radius:9px;padding:9px 12px;font-size:.82rem;line-height:1.55;margin-bottom:6px}
code{background:#08130f;padding:1px 5px;border-radius:4px;font-size:.85em}
</style>
</head>
<body>
<form class="card" method="post" action="">
    <h1>Masuk Admin</h1>
    <p class="sub">Halaman pengelolaan grup &amp; mentee.</p>
    <?php if ($noCred): ?>
        <div class="warn">Kredensial admin belum ada atau rusak. Jalankan <code>./create-admin.sh</code>
            di komputer, lalu kirim ke server (mis. <code>./push.sh</code>).</div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <div class="err"><?= adm_h($error) ?></div>
    <?php endif; ?>
    <input type="hidden" name="action" value="login">
    <label for="username">Username</label>
    <input type="text" id="username" name="username" autocomplete="username" autofocus required>
    <label for="password">Password</label>
    <input type="password" id="password" name="password" autocomplete="current-password" required>
    <button type="submit">Masuk</button>
</form>
</body>
</html><?php
    exit;
}

$action = $_POST['action'] ?? '';

// --- Keluar ---
if ($action === 'logout') {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();
    header('Location: ' . adm_self_url());
    exit;
}

// --- Masuk ---
if (!adm_logged_in() && $action === 'login') {
    $u = trim((string) ($_POST['username'] ?? ''));
    $p = (string) ($_POST['password'] ?? '');
    if (adm_cred_verify($u, $p)) {
        session_regenerate_id(true);
        $_SESSION[ADMIN_SESSION_USER] = $u;
        $_SESSION[ADMIN_SESSION_CRED] = (string) adm_cred_fingerprint();
        header('Location: ' . adm_self_url());
        exit;
    }
    adm_render_login('Username atau password salah.', adm_cred_read() === null);
}

// --- Belum masuk: tolak aksi (AJAX) & tampilkan halaman login ---
if (!adm_logged_in()) {
    if ($action !== '') {
        adm_json_out(['ok' => false, 'messages' => [adm_msg('Sesi berakhir. Muat ulang lalu masuk lagi.', 'err')]]);
    }
    adm_render_login('', adm_cred_read() === null);
}

// ======================= TOOL MANDIRI: PEMBUAT NAMA PANGGILAN =======================
// Blok ini berdiri sendiri (tidak tersambung ke fitur lain): hanya mengusulkan
// beberapa nama panggilan dari sebuah nama lengkap, lalu menandai mana yang belum
// dipakai. Tidak membuat/mengubah/menghapus file apa pun.
//
// Aturan "tidak duplikat": dicek terhadap seluruh nama yang sudah ada — semua nama
// mentee di semua grup (aktif + tempat sampah) dan nama grup. Tanpa angka.
//
// Dipanggil dari: blok AKSI ($nn = nickname_handle(...)) dan bagian HTML
// (nickname_render($root)).

/** Daftar nama yang sudah dipakai: nama grup + nama mentee (aktif & tempat sampah). */
function nickname_existing(string $root): array
{
    $taken = [];
    $add = static function (?string $name) use (&$taken): void {
        $n = strtolower(trim((string) $name));
        if ($n !== '') $taken[$n] = true;
    };

    // Grup aktif + menteenya.
    foreach (adm_groups($root) as $g) {
        $add($g);
        foreach (array_keys(adm_mentees($root . '/' . $g)) as $m) $add($m);
    }

    // Tempat sampah: <root>/.DEPRECATED/<grup>/<mentee>/ — mencakup mentee yang
    // dihapus maupun grup utuh yang dihapus (nama grup + menteenya).
    $trash = $root . '/' . TRASH_DIR;
    foreach (adm_subfolders($trash) as $g) {
        $add($g);
        foreach (array_keys(adm_mentees($trash . '/' . $g)) as $m) $add($m);
    }

    // Mentee yang tidak lagi mentoring: <root>/.DEPRECATED/.OUT/<mentee>/.
    foreach (array_keys(adm_mentees($trash . '/' . OUT_DIR)) as $m) $add($m);

    $names = array_keys($taken);
    sort($names);
    return $names;
}

/** Label urutan: pertama, kedua, ketiga, ... */
function nickname_ordinal(int $i): string
{
    $words = ['pertama', 'kedua', 'ketiga', 'keempat', 'kelima',
              'keenam', 'ketujuh', 'kedelapan', 'kesembilan', 'kesepuluh'];
    return $words[$i] ?? ('ke-' . ($i + 1));
}

/**
 * Usulan nama panggilan dari nama lengkap (tanpa angka), terurut dari yang
 * paling wajar. Tiap usulan: ['text' => slug, 'label' => asal-usul].
 */
function nickname_candidates(string $full): array
{
    $clean = preg_replace('/[^a-z0-9]+/', ' ', strtolower($full)) ?? '';
    $words = array_values(array_filter(explode(' ', trim($clean)), static fn($w) => $w !== ''));
    $n = count($words);
    if ($n === 0) return [];

    $out = [];
    $seen = [];
    $push = static function (string $text, string $label) use (&$out, &$seen): void {
        $text = adm_slug($text);
        if ($text === '' || !adm_valid_name($text) || strlen($text) > 24) return;
        if (isset($seen[$text])) return;
        $seen[$text] = true;
        $out[] = ['text' => $text, 'label' => $label];
    };
    $initials = static function (array $ws): string {
        $s = '';
        foreach ($ws as $w) $s .= $w[0];
        return $s;
    };

    // 1) Tiap kata (paling wajar sebagai nama panggilan).
    foreach ($words as $i => $w) {
        $label = 'kata ' . nickname_ordinal($i);
        if ($n > 1 && $i === $n - 1) $label = 'kata terakhir';
        $push($w, $label);
    }

    // 2) Bentuk inisial / gabungan (kalau kata tunggal sudah dipakai).
    if ($n >= 2) {
        $push($initials($words), 'inisial semua kata');
        $push($initials(array_slice($words, 0, 2)), 'inisial 2 kata pertama');
        $push($words[0] . $initials(array_slice($words, 1)), 'kata pertama + inisial sisanya');
        $push($initials(array_slice($words, 0, $n - 1)) . $words[$n - 1], 'inisial awal + kata terakhir');
        $push($words[0] . $words[$n - 1], 'kata pertama + kata terakhir');
    }
    if (strlen($words[0]) >= 5) $push(substr($words[0], 0, 4), 'singkatan kata pertama');
    if ($n >= 2 && strlen($words[$n - 1]) >= 5) $push(substr($words[$n - 1], 0, 4), 'singkatan kata terakhir');

    return $out;
}

/**
 * Tangani aksi AJAX 'gen_nickname'. Kembalikan array balasan (untuk digabung ke
 * $reply), atau null bila aksi bukan milik tool ini.
 */
function nickname_handle(string $action, string $root): ?array
{
    if ($action !== 'gen_nickname') return null;

    $full = trim((string) ($_POST['full_name'] ?? ''));
    if ($full === '') {
        return ['ok' => false, 'messages' => [adm_msg('Nama lengkap belum diisi.', 'err')]];
    }

    $takenList = nickname_existing($root);
    $taken = array_fill_keys($takenList, true);

    $cands = nickname_candidates($full);
    if (!$cands) {
        return ['ok' => false, 'messages' => [adm_msg('Nama tidak bisa diolah menjadi nama panggilan.', 'err')]];
    }

    $available = [];
    foreach ($cands as &$c) {
        $c['available'] = !isset($taken[$c['text']]);
        if ($c['available']) $available[] = $c['text'];
    }
    unset($c);

    $ok = $available !== [];
    return [
        'ok'          => $ok,
        'messages'    => [$ok
            ? adm_msg("Usulan unik: {$available[0]}.")
            : adm_msg('Semua usulan sudah dipakai. Tool ini tidak menambah angka — ubah/tambah kata pada nama lengkap.', 'err')],
        'full_name'   => $full,
        'candidates'  => $cands,
        'taken_count' => count($takenList),
    ];
}

/** Gambar UI tool (dipanggil dari bagian HTML). */
function nickname_render(string $root): void
{
    $takenCount = count(nickname_existing($root));
    ?>
<section class="panel" id="nn-panel">
    <h2>Pembuat Nama Panggilan <span class="tag muted">tool mandiri</span></h2>
    <p class="hint">
        Hasilkan usulan <strong>nama panggilan</strong> dari sebuah <strong>nama lengkap</strong>.
        Tiap usulan diperiksa terhadap <span id="nn-taken"><?= $takenCount ?></span> nama yang sudah ada
        (semua nama mentee di semua grup &amp; tempat sampah, plus nama grup) supaya tidak duplikat.
        Angka itu dihitung ulang tiap kali tool dipakai.
        Tool ini berdiri sendiri &mdash; tidak menyimpan atau mengubah apa pun.
    </p>
    <div class="row" style="margin:12px 0">
        <input type="text" id="nn-full" placeholder="Nama lengkap, mis. Ahmad Rasyid Kurniawan" autocomplete="off">
        <button id="nn-go" type="button">Buat nama panggilan</button>
    </div>
    <div id="nn-out"></div>
</section>
<style>
#nn-panel td:first-child{font-weight:700;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
#nn-panel tr.nn-rec{background:rgba(125,240,197,.06)}
#nn-panel .nn-copy{padding:4px 9px;font-size:.72rem}
</style>
<script>
(function(){
    const inp = document.getElementById('nn-full');
    const go  = document.getElementById('nn-go');
    const out = document.getElementById('nn-out');
    if (!inp || !go || !out) return;

    function esc(s){ return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

    function run(){
        const v = inp.value.trim();
        if (!v){ out.innerHTML = '<p class="hint">Isi nama lengkap dulu.</p>'; return; }
        out.innerHTML = '<p class="hint">Menghitung...</p>';
        const body = new URLSearchParams();
        body.set('action','gen_nickname');
        body.set('full_name', v);
        fetch('', {method:'POST', body, headers:{'Content-Type':'application/x-www-form-urlencoded'}})
            .then(r => r.json())
            .then(draw)
            .catch(() => { out.innerHTML = '<p class="hint">Gagal menghubungi server.</p>'; });
    }

    function draw(d){
        // Segarkan jumlah nama yang diperiksa dari server (data terbaru), bukan
        // angka yang tertanam saat halaman dimuat.
        if (typeof d.taken_count === 'number'){
            const t = document.getElementById('nn-taken');
            if (t) t.textContent = d.taken_count;
        }
        const cs = Array.isArray(d.candidates) ? d.candidates : [];
        if (!cs.length){
            const msg = (d.messages && d.messages[0] && d.messages[0].text) || 'Tidak ada usulan.';
            out.innerHTML = '<p class="hint">' + esc(msg) + '</p>';
            return;
        }
        let first = true;
        let rows = '';
        cs.forEach(c => {
            const rec = c.available && first;
            if (c.available) first = false;
            rows += '<tr class="' + (rec ? 'nn-rec' : '') + '">'
                 +  '<td>' + esc(c.text) + '</td>'
                 +  '<td>' + esc(c.label) + '</td>'
                 +  '<td>' + (c.available ? '<span class="tag mentee">tersedia</span>' : '<span class="tag trash">dipakai</span>')
                 +      (rec ? ' <span class="tag mentor">rekomendasi</span>' : '') + '</td>'
                 +  '<td style="width:1%"><button class="ghost nn-copy" type="button" data-text="' + esc(c.text) + '">Salin</button></td>'
                 +  '</tr>';
        });
        const anyAvail = cs.some(c => c.available);
        out.innerHTML = '<table><thead><tr><th>Nama panggilan</th><th>Asal</th><th>Status</th><th></th></tr></thead><tbody>'
                      + rows + '</tbody></table>'
                      + (anyAvail
                          ? '<p class="hint">Pilih salah satu yang berstatus <em>tersedia</em>. Baris <em>rekomendasi</em> adalah usulan pertama yang belum dipakai.</p>'
                          : '<p class="hint" style="color:var(--danger)">Semua usulan sudah dipakai. Tool ini tidak menambah angka &mdash; ubah atau tambah kata pada nama lengkap.</p>');
    }

    go.addEventListener('click', run);
    inp.addEventListener('keydown', e => { if (e.key === 'Enter'){ e.preventDefault(); run(); } });
    out.addEventListener('click', e => {
        const b = e.target.closest('.nn-copy');
        if (!b) return;
        const text = b.dataset.text || '';
        const done = () => { const old = b.textContent; b.textContent = 'Tersalin'; setTimeout(() => { b.textContent = old; }, 1200); };
        if (navigator.clipboard && navigator.clipboard.writeText){
            navigator.clipboard.writeText(text).then(done).catch(done);
        } else { done(); }
    });
})();
</script>
    <?php
}

// ======================= AKSI (AJAX) =======================
if ($action !== '') {
    $root = realpath(ADMIN_ROOT) ?: ADMIN_ROOT;
    $reply = ['ok' => false, 'messages' => []];

    try {
        if ($action === 'create_group') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $slug = adm_slug($name);

            if (!adm_valid_name($slug)) {
                $reply['messages'][] = adm_msg('Nama grup tidak valid. Pakai huruf, angka, _ atau - saja.', 'err');
            } elseif (is_dir($root . '/' . $slug)) {
                $reply['messages'][] = adm_msg("Grup '$slug' sudah ada.", 'err');
            } else {
                $tpl = __DIR__ . '/_template';
                $new = $root . '/' . $slug;
                mkdir($new, 0755, true);
                copy($tpl . '/index.php', $new . '/index.php');

                // index.php: ganti placeholder {{GROUP}} bila ada, agar komentar
                // menunjuk folder sebenarnya.
                $idx = (string) @file_get_contents($new . '/index.php');
                if (str_contains($idx, '{{GROUP}}')) {
                    @file_put_contents($new . '/index.php', str_replace('{{GROUP}}', $slug, $idx));
                }

                // index.php: arahkan manifest ke manifest_<grup>.json (bukan placeholder).
                adm_write_manifest_path($new . '/index.php', adm_group_manifest_raw_url($slug));

                // Tulis salinan lokal manifest grup, lalu terbitkan ke repo bila token ada.
                $gm = adm_write_group_manifest($slug);
                if ($gm['ok'] && adm_gh_token() !== '') {
                    $pr = adm_gh_put(adm_group_manifest_filename($slug), $gm['json'], "manifest: grup $slug");
                    $reply['messages'][] = adm_msg(
                        $pr['ok']
                            ? "Manifest grup '$slug' diterbitkan ke repo."
                            : "Manifest grup dibuat lokal, tapi gagal ke repo: {$pr['error']}",
                        $pr['ok'] ? 'ok' : 'err'
                    );
                } else {
                    $reply['messages'][] = adm_msg(
                        "Manifest grup dibuat lokal. Terbitkan lewat tombol 'Tulis manifest grup'.",
                        'ok'
                    );
                }

                // target_settings.json sengaja TIDAK dibuat di sini. File itu hanya
                // dibuat saat admin menyimpan target dari editor "Target & analisis",
                // supaya grup baru tidak punya file target yang tidak diperlukan.

                $reply['ok'] = true;
                $reply['messages'][] = adm_msg(
                    "Grup '$slug' dibuat. Buka 'Target & analisis' untuk mengisi target bila perlu."
                );
            }
        }

        if ($action === 'create_mentee') {
            $group = trim((string) ($_POST['group'] ?? ''));
            $name  = trim((string) ($_POST['name'] ?? ''));
            $slug  = adm_slug($name);

            if (!adm_valid_name($slug)) {
                $reply['messages'][] = adm_msg('Nama mentee tidak valid. Pakai huruf, angka, _ atau - saja.', 'err');
            } elseif (!adm_valid_name($group) || !is_dir($root . '/' . $group)) {
                $reply['messages'][] = adm_msg('Grup tidak ditemukan.', 'err');
            } else {
                $tpl  = __DIR__ . '/_template/mentee';
                $dest = $root . '/' . $group . '/' . $slug;

                // Validasi duplikat, urutan prioritas:
                //   1. sudah ada di grup ini        -> tolak
                //   2. ada di grup LAIN (aktif)     -> tolak (nama unik)
                //   3. ada di tempat sampah         -> PULIHKAN, jangan buat baru
                if (is_dir($dest)) {
                    $reply['messages'][] = adm_msg("Mentee '$slug' sudah ada di grup '$group'.", 'err');

                } else {
                    $activeElsewhere = adm_find_active_mentee($root, $slug, $group);
                    $trashed         = adm_find_trashed_mentee($root, $slug);

                    if ($activeElsewhere) {
                        $reply['messages'][] = adm_msg(
                            "Mentee '$slug' sudah ada di grup '{$activeElsewhere['group']}'. "
                            . 'Satu mentee hanya boleh punya satu folder. Tidak boleh duplikat.',
                            'err'
                        );
                    } elseif ($trashed) {
                        if (rename($trashed['path'], $dest)) {
                            $reply['ok'] = true;
                            $reply['messages'][] = adm_msg(
                                "Mentee '$slug' ditemukan di tempat sampah grup '{$trashed['group']}' "
                                . "- data lama dipulihkan ke grup '$group', bukan dibuat baru."
                            );
                            // Manifest lama menunjuk grup asal; buat ulang untuk grup ini.
                            foreach (adm_publish_mentee_manifest($root, $group, $slug, is_file($dest . '/' . MENTOR_FLAG)) as $m) {
                                $reply['messages'][] = $m;
                            }
                        } else {
                            $reply['messages'][] = adm_msg('Gagal memulihkan dari tempat sampah.', 'err');
                        }
                    } else {
                        mkdir($dest, 0755, true);
                        copy($tpl . '/index.php', $dest . '/index.php');
                        copy($tpl . '/download.php', $dest . '/download.php');

                        // Ganti placeholder {{MENTEE}} bila ada (komentar menunjuk folder sebenarnya).
                        $midx = (string) @file_get_contents($dest . '/index.php');
                        if (str_contains($midx, '{{MENTEE}}')) {
                            @file_put_contents($dest . '/index.php', str_replace('{{MENTEE}}', $slug, $midx));
                        }

                        file_put_contents($dest . '/data_amalan.json', "{}\n");
                        $reply['ok'] = true;
                        $reply['messages'][] = adm_msg("Mentee '$slug' dibuat di grup '$group'.");
                        foreach (adm_publish_mentee_manifest($root, $group, $slug, false) as $m) {
                            $reply['messages'][] = $m;
                        }
                    }
                }
            }
        }

        // ---- Target settings grup (target_settings.json) ----
        if ($action === 'save_targets') {
            $group = trim((string) ($_POST['group'] ?? ''));

            if (!adm_valid_name($group) || !is_dir($root . '/' . $group)) {
                $reply['messages'][] = adm_msg('Grup tidak ditemukan.', 'err');
            } else {
                $raw  = (string) ($_POST['settings'] ?? '');
                $data = json_decode($raw, true);

                if (!is_array($data)) {
                    $reply['messages'][] = adm_msg('Data target tidak valid (bukan JSON).', 'err');
                } else {
                    $clean = adm_normalize_targets($data, $group);
                    $res   = adm_write_targets(adm_target_file($root, $group), $clean);
                    if ($res['ok']) {
                        $reply['ok'] = true;
                        $reply['messages'][] = adm_msg(
                            "Target grup '$group' disimpan (" . count($clean['rules']) . ' aturan).'
                        );
                    } else {
                        $reply['messages'][] = adm_msg($res['error'], 'err');
                    }
                }
            }
        }

        // ---- GitHub repo manifest ----
        if ($action === 'set_token') {
            $r = adm_gh_token_set((string) ($_POST['token'] ?? ''));
            $reply['ok'] = $r['ok'];
            $reply['messages'][] = adm_msg($r['msg'], $r['ok'] ? 'ok' : 'err');
        }

        if ($action === 'test_gh') {
            $me = adm_gh_api('GET', 'https://api.github.com/user');
            if ($me['ok']) {
                $reply['ok'] = true;
                $reply['messages'][] = adm_msg("Tersambung sebagai @{$me['data']['login']}.");
                $repo = adm_gh_api('GET', 'https://api.github.com/repos/' . MANIFEST_REPO);
                if ($repo['ok']) {
                    $reply['messages'][] = adm_msg("Repo '{$repo['data']['full_name']}' dapat diakses (branch default: {$repo['data']['default_branch']}).");
                } else {
                    $reply['messages'][] = adm_msg('Repo tidak bisa diakses: ' . $repo['error'], 'err');
                }
            } else {
                $reply['messages'][] = adm_msg($me['error'], 'err');
            }
        }

        if ($action === 'gh_list') {
            $r = adm_gh_list();
            $reply['ok'] = $r['ok'];
            $reply['files'] = $r['ok'] ? $r['data'] : [];
            if (!$r['ok']) $reply['messages'][] = adm_msg($r['error'], 'err');
            else $reply['messages'][] = adm_msg(count($r['data']) . ' manifest ditemukan di repo.');
        }

        if ($action === 'gh_read') {
            $file = trim((string) ($_POST['file'] ?? ''));
            $r = adm_gh_get($file);
            if (!$r['ok']) {
                $reply['messages'][] = adm_msg($r['error'], 'err');
            } else {
                $raw = base64_decode((string) ($r['data']['content'] ?? ''), true);
                $reply['ok'] = true;
                $reply['content'] = $raw === false ? '' : $raw;
                $reply['messages'][] = adm_msg('Isi ' . $file . ' dibaca dari repo.');
            }
        }

        if ($action === 'gh_publish') {
            $group = trim((string) ($_POST['group'] ?? ''));
            $name  = trim((string) ($_POST['name'] ?? ''));

            if (!adm_valid_name($group) || !adm_valid_name($name)) {
                $reply['messages'][] = adm_msg('Nama grup atau mentee tidak valid.', 'err');
            } else {
                // Generate dari sumber kebenaran, lalu tulis langsung ke repo.
                $manifest = adm_build_manifest($name, $group, is_file($root . '/' . $group . '/' . $name . '/' . MENTOR_FLAG));
                $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                // Simpan salinan lokal sebagai cadangan.
                if (!is_dir(MANIFEST_LOCAL_DIR)) mkdir(MANIFEST_LOCAL_DIR, 0755, true);
                @file_put_contents(MANIFEST_LOCAL_DIR . '/' . adm_manifest_filename($name), $json . "\n");

                $r = adm_gh_put(adm_manifest_filename($name), $json . "\n", "manifest: $group/$name");
                if ($r['ok']) {
                    $reply['ok'] = true;
                    $reply['messages'][] = adm_msg("Manifest '$name' ditulis ke repo " . MANIFEST_REPO . '.');
                    $reply['messages'][] = adm_msg('Raw: ' . adm_manifest_raw_url($name));
                } else {
                    $reply['messages'][] = adm_msg('Gagal menulis ke repo: ' . $r['error'], 'err');
                }
            }
        }

        if ($action === 'gh_publish_all') {
            $ok = 0; $fail = 0; $errors = [];
            foreach (adm_groups($root) as $g) {
                // Manifest halaman dasbor grup (manifest_<grup>.json).
                $gm = adm_write_group_manifest($g);
                if ($gm['ok']) {
                    $r = adm_gh_put(adm_group_manifest_filename($g), $gm['json'], "manifest: grup $g");
                    if ($r['ok']) $ok++;
                    else { $fail++; $errors[] = "grup $g: " . $r['error']; }
                } else {
                    $fail++; $errors[] = "grup $g: " . $gm['error'];
                }

                foreach (adm_subfolders($root . '/' . $g) as $m) {
                    $manifest = adm_build_manifest($m, $g, is_file($root . '/' . $g . '/' . $m . '/' . MENTOR_FLAG));
                    $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                    if (!is_dir(MANIFEST_LOCAL_DIR)) mkdir(MANIFEST_LOCAL_DIR, 0755, true);
                    @file_put_contents(MANIFEST_LOCAL_DIR . '/' . adm_manifest_filename($m), $json . "\n");

                    $r = adm_gh_put(adm_manifest_filename($m), $json . "\n", "manifest: $g/$m");
                    if ($r['ok']) $ok++;
                    else { $fail++; $errors[] = "$m: " . $r['error']; }
                }
            }
            $reply['ok'] = $ok > 0;
            $reply['messages'][] = adm_msg("$ok manifest ditulis, $fail gagal.");
            foreach (array_slice($errors, 0, 5) as $e) $reply['messages'][] = adm_msg($e, 'err');
        }

        if ($action === 'gh_delete') {
            $file = trim((string) ($_POST['file'] ?? ''));
            $r = adm_gh_delete($file, 'hapus manifest dari admin');
            if ($r['ok']) {
                $reply['ok'] = true;
                $reply['messages'][] = adm_msg("$file dihapus dari repo.");
            } else {
                $reply['messages'][] = adm_msg('Gagal menghapus: ' . $r['error'], 'err');
            }
        }

        // ---- Manifest PWA ----
        if ($action === 'gen_manifest') {
            $group = trim((string) ($_POST['group'] ?? ''));
            $name  = trim((string) ($_POST['name'] ?? ''));

            if (!adm_valid_name($group) || !adm_valid_name($name)) {
                $reply['messages'][] = adm_msg('Nama grup atau mentee tidak valid.', 'err');
            } elseif (!is_dir($root . '/' . $group . '/' . $name)) {
                $reply['messages'][] = adm_msg('Mentee tidak ditemukan.', 'err');
            } else {
                $res = adm_write_manifest($name, $group, is_file($root . '/' . $group . '/' . $name . '/' . MENTOR_FLAG));
                if ($res['ok']) {
                    $reply['ok'] = true;
                    $reply['messages'][] = adm_msg("Manifest untuk '$name' dibuat: " . adm_manifest_filename($name));
                    $reply['manifest_url'] = $res['url'];
                    $reply['messages'][] = adm_msg('URL raw: ' . $res['url']);
                } else {
                    $reply['messages'][] = adm_msg($res['error'], 'err');
                }
            }
        }

        if ($action === 'gen_manifest_all') {
            $count = 0; $failed = 0;
            foreach (adm_groups($root) as $g) {
                foreach (adm_subfolders($root . '/' . $g) as $m) {
                    $res = adm_write_manifest($m, $g, is_file($root . '/' . $g . '/' . $m . '/' . MENTOR_FLAG));
                    $res['ok'] ? $count++ : $failed++;
                }
            }
            $reply['ok'] = $count > 0;
            $reply['messages'][] = adm_msg("$count manifest dibuat." . ($failed ? " $failed gagal." : ''));
            $reply['messages'][] = adm_msg('Upload manual ke repo ' . MANIFEST_REPO . ' (lihat panduan di bawah).');
        }

        /** Tuliskan $manifestPath ke index.php mentee agar menunjuk raw URL. */
        if ($action === 'apply_manifest_link') {
            $group = trim((string) ($_POST['group'] ?? ''));
            $name  = trim((string) ($_POST['name'] ?? ''));
            $file  = $root . '/' . $group . '/' . $name . '/index.php';

            if (!adm_valid_name($group) || !adm_valid_name($name) || !is_file($file)) {
                $reply['messages'][] = adm_msg('Mentee tidak ditemukan.', 'err');
            } elseif (adm_write_manifest_path($file, adm_manifest_raw_url($name))) {
                $reply['ok'] = true;
                $reply['messages'][] = adm_msg("index.php mentee '$name' diarahkan ke manifest raw.");
            } else {
                $reply['messages'][] = adm_msg('Gagal menulis index.php. Periksa hak akses.', 'err');
            }
        }

        /**
         * Terbitkan manifest halaman dasbor grup ke repo, lalu arahkan
         * index.php grup ke raw URL-nya (memperbaiki placeholder template).
         */
        if ($action === 'publish_group') {
            $group = trim((string) ($_POST['group'] ?? ''));

            if (!adm_valid_name($group) || !is_dir($root . '/' . $group)) {
                $reply['messages'][] = adm_msg('Grup tidak ditemukan.', 'err');
            } else {
                $gm = adm_write_group_manifest($group);
                if (!$gm['ok']) {
                    $reply['messages'][] = adm_msg($gm['error'], 'err');
                } else {
                    $pr = adm_gh_put(adm_group_manifest_filename($group), $gm['json'], "manifest: grup $group");
                    if ($pr['ok']) {
                        $reply['ok'] = true;
                        $reply['messages'][] = adm_msg("Manifest grup '$group' ditulis ke repo.");
                    } else {
                        $reply['messages'][] = adm_msg('Gagal menulis ke repo: ' . $pr['error'], 'err');
                    }

                    $idx = $root . '/' . $group . '/index.php';
                    if (adm_write_manifest_path($idx, adm_group_manifest_raw_url($group))) {
                        $reply['messages'][] = adm_msg("index.php grup '$group' diarahkan ke manifest raw.");
                    } else {
                        $reply['messages'][] = adm_msg('Gagal menulis index.php grup.', 'err');
                    }
                }
            }
        }

        if ($action === 'toggle_mentor') {
            $group = trim((string) ($_POST['group'] ?? ''));
            $name  = trim((string) ($_POST['name'] ?? ''));
            $dir   = $root . '/' . $group . '/' . $name;

            if (!adm_valid_name($group) || !adm_valid_name($name) || !is_dir($dir)) {
                $reply['messages'][] = adm_msg('Mentee tidak ditemukan.', 'err');
            } else {
                $flag = $dir . '/' . MENTOR_FLAG;
                if (is_file($flag)) {
                    unlink($flag);
                    $reply['ok'] = true;
                    $reply['messages'][] = adm_msg("'$name' tidak lagi menjadi mentor grup '$group'.");
                } else {
                    // Hanya satu mentor per grup.
                    foreach (adm_subfolders($root . '/' . $group) as $m) {
                        $f = $root . '/' . $group . '/' . $m . '/' . MENTOR_FLAG;
                        if (is_file($f)) @unlink($f);
                    }
                    file_put_contents($flag, "1\n");
                    $reply['ok'] = true;
                    $reply['messages'][] = adm_msg("'$name' sekarang jadi mentor grup '$group'.");
                }
            }
        }

        if ($action === 'trash_mentee') {
            $group = trim((string) ($_POST['group'] ?? ''));
            $name  = trim((string) ($_POST['name'] ?? ''));
            $src   = $root . '/' . $group . '/' . $name;

            if (!adm_valid_name($group) || !adm_valid_name($name) || !is_dir($src)) {
                $reply['messages'][] = adm_msg('Mentee tidak ditemukan.', 'err');
            } else {
                $trash = $root . '/' . TRASH_DIR . '/' . $group;
                if (!is_dir($trash)) mkdir($trash, 0755, true);
                $dest = $trash . '/' . $name;
                if (is_dir($dest)) $dest = $trash . '/' . $name . '-' . date('Ymd-His');

                if (rename($src, $dest)) {
                    $reply['ok'] = true;
                    $reply['messages'][] = adm_msg("'$name' dipindahkan ke tempat sampah (dapat dipulihkan).");
                } else {
                    $reply['messages'][] = adm_msg('Gagal memindahkan.', 'err');
                }
            }
        }

        if ($action === 'restore_mentee') {
            $group = trim((string) ($_POST['group'] ?? ''));
            $name  = trim((string) ($_POST['name'] ?? ''));
            $src   = $root . '/' . TRASH_DIR . '/' . $group . '/' . $name;
            $target = trim((string) ($_POST['target'] ?? $group));

            if (!adm_valid_name($group) || !adm_valid_name($name) || !is_dir($src)) {
                $reply['messages'][] = adm_msg('Data di tempat sampah tidak ditemukan.', 'err');
            } elseif (!adm_valid_name($target)) {
                $reply['messages'][] = adm_msg('Grup tujuan tidak valid.', 'err');
            } else {
                $destGroup = $root . '/' . $target;
                $madeGroup = false;

                // Bila grup tujuan belum ada di root tapi ada di .DEPRECATED,
                // pulihkan grupnya ke root (hanya file tingkat grup yang disalin),
                // lalu menteenya dipindah ke dalamnya. Mentee lain di grup itu
                // tetap di .DEPRECATED.
                if (!is_dir($destGroup)) {
                    $trashGroup = $root . '/' . TRASH_DIR . '/' . $target;
                    if (is_dir($trashGroup)) {
                        if (@mkdir($destGroup, 0755, true)) {
                            foreach (scandir($trashGroup) ?: [] as $e) {
                                if ($e === '.' || $e === '..') continue;
                                $from = $trashGroup . '/' . $e;
                                if (is_file($from)) @copy($from, $destGroup . '/' . $e);
                            }
                            // Kalau grup asal tidak punya index.php, buat dari template.
                            if (!is_file($destGroup . '/index.php') && is_file(__DIR__ . '/_template/index.php')) {
                                @copy(__DIR__ . '/_template/index.php', $destGroup . '/index.php');
                            }
                            if (is_file($destGroup . '/index.php')) {
                                adm_write_manifest_path($destGroup . '/index.php', adm_group_manifest_raw_url($target));
                            }
                            $madeGroup = true;
                        }
                    }
                }

                if (!is_dir($destGroup)) {
                    $reply['messages'][] = adm_msg('Grup tujuan tidak valid.', 'err');
                } else {
                    $dest = $destGroup . '/' . $name;
                    if (is_dir($dest)) {
                        $reply['messages'][] = adm_msg("Mentee '$name' sudah ada di grup '$target'.", 'err');
                    } elseif (rename($src, $dest)) {
                        $reply['ok'] = true;
                        $reply['messages'][] = adm_msg("'$name' dipulihkan ke grup '$target'.");
                        if ($madeGroup) {
                            $reply['messages'][] = adm_msg("Grup '$target' dipulihkan ke root (mentee lain tetap di tempat sampah).");
                        }
                        // Manifest lama menunjuk grup asal; buat ulang untuk grup ini.
                        foreach (adm_publish_mentee_manifest($root, $target, $name, is_file($dest . '/' . MENTOR_FLAG)) as $m) {
                            $reply['messages'][] = $m;
                        }
                    } else {
                        $reply['messages'][] = adm_msg('Gagal memulihkan.', 'err');
                    }
                }
            }
        }

        // ---- Tandai mentee (yang sudah dihapus) sebagai "tidak lagi mentoring" ----
        if ($action === 'retire_mentee') {
            $group = trim((string) ($_POST['group'] ?? ''));
            $name  = trim((string) ($_POST['name'] ?? ''));
            $src   = $root . '/' . TRASH_DIR . '/' . $group . '/' . $name;

            if (!adm_valid_name($group) || !adm_valid_name($name) || !is_dir($src)) {
                $reply['messages'][] = adm_msg('Mentee di tempat sampah tidak ditemukan.', 'err');
            } else {
                $out = $root . '/' . TRASH_DIR . '/' . OUT_DIR;
                if (!is_dir($out)) mkdir($out, 0755, true);
                $dest = $out . '/' . $name;
                if (is_dir($dest)) $dest = $out . '/' . $name . '-' . date('Ymd-His');

                if (rename($src, $dest)) {
                    $reply['ok'] = true;
                    $reply['messages'][] = adm_msg("'$name' ditandai tidak lagi mentoring.");
                } else {
                    $reply['messages'][] = adm_msg('Gagal menandai mentee.', 'err');
                }
            }
        }

        // ---- Aktifkan kembali mentee yang "tidak lagi mentoring" ke sebuah grup ----
        // Grup tujuan boleh grup aktif di root, atau grup yang ada di .DEPRECATED
        // (grup tidak aktif). Tidak memulihkan grupnya, cukup memindahkan menteenya.
        if ($action === 'reactivate_mentee') {
            $name   = trim((string) ($_POST['name'] ?? ''));
            $target = trim((string) ($_POST['target'] ?? ''));
            $src    = $root . '/' . TRASH_DIR . '/' . OUT_DIR . '/' . $name;

            $destGroup = '';
            if (adm_valid_name($target)) {
                if (is_dir($root . '/' . $target)) {
                    $destGroup = $root . '/' . $target;
                } elseif (is_dir($root . '/' . TRASH_DIR . '/' . $target)) {
                    $destGroup = $root . '/' . TRASH_DIR . '/' . $target;
                }
            }

            if (!adm_valid_name($name) || !is_dir($src)) {
                $reply['messages'][] = adm_msg('Mentee tidak lagi mentoring tidak ditemukan.', 'err');
            } elseif ($destGroup === '') {
                $reply['messages'][] = adm_msg('Grup tujuan tidak valid.', 'err');
            } else {
                $dest = $destGroup . '/' . $name;
                if (is_dir($dest)) {
                    $reply['messages'][] = adm_msg("Mentee '$name' sudah ada di grup '$target'.", 'err');
                } elseif (rename($src, $dest)) {
                    $reply['ok'] = true;
                    $reply['messages'][] = adm_msg("'$name' dipindahkan ke grup '$target'.");
                    // Manifest hanya dibuat ulang bila grupnya aktif di root (bisa diakses).
                    if (is_dir($root . '/' . $target)) {
                        foreach (adm_publish_mentee_manifest($root, $target, $name, is_file($dest . '/' . MENTOR_FLAG)) as $m) {
                            $reply['messages'][] = $m;
                        }
                    } else {
                        $reply['messages'][] = adm_msg("Grup '$target' tidak aktif di root; manifest tidak diubah.");
                    }
                } else {
                    $reply['messages'][] = adm_msg('Gagal mengaktifkan kembali.', 'err');
                }
            }
        }

        // ---- Hapus/pulihkan SELURUH grup (pindah ke tempat sampah) ----
        if ($action === 'trash_group') {
            $group = trim((string) ($_POST['group'] ?? ''));
            $src   = $root . '/' . $group;

            if (!adm_valid_name($group) || !is_dir($src)) {
                $reply['messages'][] = adm_msg('Grup tidak ditemukan.', 'err');
            } else {
                $trash = $root . '/' . TRASH_DIR;
                if (!is_dir($trash)) mkdir($trash, 0755, true);

                // Struktur tetap seragam: .DEPRECATED/<grup>/<mentee>/.
                // Bila folder itu sudah ada (mis. mentee yang lebih dulu dihapus),
                // isinya digabungkan sehingga tidak ada folder ".groups" terpisah.
                if (adm_move_merge($src, $trash . '/' . $group)) {
                    $reply['ok'] = true;
                    $reply['messages'][] = adm_msg(
                        "Grup '$group' beserta seluruh mentee dipindahkan ke tempat sampah (dapat dipulihkan)."
                    );
                } else {
                    $reply['messages'][] = adm_msg('Gagal memindahkan grup ke tempat sampah.', 'err');
                }
            }
        }

        if ($action === 'restore_group') {
            $group = trim((string) ($_POST['group'] ?? ''));
            $src   = $root . '/' . TRASH_DIR . '/' . $group;

            if (!adm_valid_name($group) || !is_dir($src)) {
                $reply['messages'][] = adm_msg('Grup di tempat sampah tidak ditemukan.', 'err');
            } elseif (is_dir($root . '/' . $group)) {
                $reply['messages'][] = adm_msg(
                    "Grup '$group' sudah ada di root. Hapus dulu grup itu sebelum memulihkan.",
                    'err'
                );
            } elseif (rename($src, $root . '/' . $group)) {
                $reply['ok'] = true;
                $reply['messages'][] = adm_msg("Grup '$group' dipulihkan dari tempat sampah.");
            } else {
                $reply['messages'][] = adm_msg('Gagal memulihkan grup.', 'err');
            }
        }

        // ---- Tool mandiri: pembuat nama panggilan ----
        $nn = nickname_handle($action, $root);
        if ($nn !== null) {
            $reply = array_merge($reply, $nn);
        }
    } catch (Throwable $e) {
        $reply['messages'][] = adm_msg('Kesalahan: ' . $e->getMessage(), 'err');
    }

    adm_json_out($reply);
}

// ======================= TAMPILKAN HALAMAN =======================
$root    = realpath(ADMIN_ROOT) ?: ADMIN_ROOT;
$groups  = adm_groups($root);
$trashRoot = $root . '/' . TRASH_DIR;
$trashAll  = is_dir($trashRoot) ? adm_subfolders($trashRoot) : []; // grup di .DEPRECATED (tanpa .OUT)
// Mentee yang tidak lagi mentoring: .DEPRECATED/.OUT/<mentee>/.
$outDir     = $trashRoot . '/' . OUT_DIR;
$outMentees = is_dir($outDir) ? adm_mentees($outDir) : [];
// Semua nama grup yang dikenal: aktif di root + yang ada di .DEPRECATED.
$allGroups  = array_values(array_unique(array_merge($groups, $trashAll)));
sort($allGroups);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Grup Mentoring</title>
<link rel="icon" href="<?= adm_h(ICON_BASE) ?>/icon-192.png?rev=<?= adm_h(substr(ADM_ASSET_REV, 0, 12)) ?>">
<style>
:root{--bg:#061612;--surface:#0b1f1b;--surface2:#071614;--line:rgba(158,243,208,.14);
      --line2:rgba(158,243,208,.22);--text:#eefbf5;--muted:#97b2a8;--accent:#7df0c5;
      --accent2:#ffd27d;--danger:#ff8b8b;--ok:#8ff0b0;
      --out:#c8b0ff}
*{box-sizing:border-box}
body{margin:0;font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);padding:28px 22px 70px}
.shell{max-width:1200px;margin:0 auto}
header{margin-bottom:22px}
.head-row{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
.who{display:flex;align-items:center;gap:10px;margin:0}
.who-name{color:var(--muted);font-size:.9rem}
h1{font-size:1.7rem;margin:0 0 6px}
h2{font-size:1.1rem;margin:0 0 12px}
.sub{color:var(--muted);font-size:.9rem;margin:0}
.panel{background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:18px;margin-bottom:18px}

/* Kartu panel yang bisa dilipat (mis. Tempat Sampah, tertutup secara default). */
details.panel{padding:0}
details.panel>summary{cursor:pointer;padding:18px;font-weight:700;font-size:1.1rem;display:flex;gap:8px;align-items:center;flex-wrap:wrap;list-style:none}
details.panel>summary::-webkit-details-marker{display:none}
details.panel>summary::before{content:'▸';color:var(--muted);font-size:.95rem}
details.panel[open]>summary::before{content:'▾'}
details.panel>summary:hover{color:var(--accent)}
details.panel .panel-body{padding:0 18px 18px}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
input[type=text]{background:#08130f;border:1px solid var(--line2);color:var(--text);border-radius:9px;padding:9px 12px;font:inherit;flex:1;min-width:170px}
input[type=text]:focus{outline:2px solid var(--accent);outline-offset:1px}
select{background:#08130f;border:1px solid var(--line2);color:var(--text);border-radius:9px;padding:9px 12px;font:inherit}
button{font:inherit;border-radius:9px;padding:9px 14px;cursor:pointer;border:0;font-weight:700;background:var(--accent);color:#041611}
button.ghost{background:transparent;border:1px solid var(--line2);color:var(--muted)}
button.warn{background:transparent;border:1px solid rgba(255,139,139,.4);color:var(--danger)}
button:hover{filter:brightness(1.08)}
table{width:100%;border-collapse:collapse;font-size:.9rem}
th{text-align:left;padding:9px 10px;color:var(--muted);font-size:.72rem;text-transform:uppercase;letter-spacing:.09em;border-bottom:1px solid var(--line2)}
td{padding:9px 10px;border-bottom:1px solid var(--line)}
tr:last-child td{border-bottom:0}
.tag{display:inline-block;padding:2px 8px;border-radius:99px;font-size:.72rem;font-weight:700}
.tag.mentor{background:rgba(255,210,125,.15);color:var(--accent2);border:1px solid rgba(255,210,125,.35)}
.tag.mentee{background:rgba(125,240,197,.1);color:var(--accent);border:1px solid rgba(125,240,197,.3)}
.tag.trash{background:rgba(255,139,139,.12);color:var(--danger);border:1px solid rgba(255,139,139,.3)}
.tag.muted{background:rgba(151,178,168,.12);color:var(--muted);border:1px solid var(--line)}
.tag.out{background:rgba(200,176,255,.14);color:var(--out);border:1px solid rgba(200,176,255,.4)}
.out-name{color:var(--out);font-weight:700}
tr.out>td{background:rgba(200,176,255,.06)}
.msgs{margin:14px 0 0;padding:0;list-style:none}
.msgs li{padding:8px 12px;border-radius:9px;margin-bottom:7px;font-size:.88rem}
.msgs .ok{background:rgba(143,240,176,.1);border:1px solid rgba(143,240,176,.3);color:var(--ok)}
.msgs .err{background:rgba(255,139,139,.1);border:1px solid rgba(255,139,139,.3);color:var(--danger)}
.hint{color:var(--muted);font-size:.82rem;line-height:1.6;margin:10px 0 0}
.empty{color:var(--muted);padding:14px;text-align:center;font-size:.9rem}
code{background:#08130f;padding:1px 5px;border-radius:4px;font-size:.85em}

/* --- Editor target_settings.json --- */
details.tgt{margin-top:14px;border:1px solid var(--line);border-radius:12px;background:var(--surface2)}
details.tgt>summary{cursor:pointer;padding:11px 14px;font-weight:700;font-size:.88rem;display:flex;gap:8px;align-items:center;flex-wrap:wrap;list-style:none}
details.tgt>summary::-webkit-details-marker{display:none}
details.tgt>summary::before{content:'▸';color:var(--muted);font-size:.9rem}
details.tgt[open]>summary::before{content:'▾'}
.tgt-body{padding:2px 14px 16px}
.tgt-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:10px 0}
.tgt-field{display:flex;flex-direction:column;gap:4px;min-width:0}
.tgt-field>label{font-size:.7rem;color:var(--muted);text-transform:uppercase;letter-spacing:.06em}
.tgt-field input,.tgt-field select,.tgt-field textarea{background:#08130f;border:1px solid var(--line2);color:var(--text);border-radius:8px;padding:7px 9px;font:inherit;width:100%}
.tgt-field textarea{min-height:54px;resize:vertical}
.tgt-field small{color:var(--muted);font-size:.7rem;line-height:1.4}
.tgt-rule{border:1px solid var(--line);border-radius:12px;padding:12px;margin-bottom:10px;background:rgba(255,255,255,.015)}
.tgt-rule-head{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px}
.tgt-rule-head strong{font-size:.9rem}
.tgt-sep{border:0;border-top:1px dashed var(--line2);margin:14px 0}
.tgt-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:6px}
.tgt-note{color:var(--muted);font-size:.78rem}
button.small{padding:5px 10px;font-size:.75rem}
/* Popup pilih mentee untuk "Salin pesan WhatsApp" */
.wa-modal{position:fixed;inset:0;z-index:60;display:flex;align-items:center;justify-content:center;
          background:rgba(0,0,0,.55);padding:20px;backdrop-filter:blur(2px)}
.wa-modal[hidden]{display:none}
.wa-box{width:100%;max-width:420px;max-height:80vh;overflow:auto;background:var(--surface);
        border:1px solid var(--line2);border-radius:14px;padding:18px;box-shadow:0 18px 50px rgba(0,0,0,.45)}
.wa-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:4px}
.wa-head strong{font-size:1rem}
.wa-close{background:transparent;border:0;color:var(--muted);font-size:1.4rem;line-height:1;
          cursor:pointer;padding:2px 8px;border-radius:8px}
.wa-close:hover{color:var(--text);background:rgba(255,255,255,.06)}
.wa-list{display:flex;flex-direction:column;gap:8px;margin-top:12px}
.wa-item{width:100%;text-align:left;background:#08130f;border:1px solid var(--line2);color:var(--text);
         border-radius:9px;padding:10px 12px;font:inherit;font-weight:600;cursor:pointer}
.wa-item:hover{border-color:var(--accent);background:#0a1a15}
.wa-item.copied{border-color:var(--ok);color:var(--ok)}
</style>
</head>
<body>
<div class="shell">
<header>
    <div class="head-row">
        <div>
            <h1>Admin Grup Mentoring</h1>
            <p class="sub">Grup dibaca dari folder di root (kecuali <code>test</code>). Mentee berada di dalam folder grup.
               Folder <code>.DEPRECATED</code> adalah tempat sampah dan dapat dipulihkan.</p>
        </div>
        <form method="post" class="who">
            <input type="hidden" name="action" value="logout">
            <span class="who-name"><?= adm_h((string) ($_SESSION[ADMIN_SESSION_USER] ?? '')) ?></span>
            <button class="ghost" type="submit">Keluar</button>
        </form>
    </div>
</header>

<ul class="msgs" id="msgs"></ul>

<div class="wa-modal" id="wa-modal" hidden>
    <div class="wa-box" role="dialog" aria-modal="true" aria-labelledby="wa-title">
        <div class="wa-head">
            <strong id="wa-title">Salin pesan WhatsApp</strong>
            <button type="button" class="wa-close" aria-label="Tutup">&times;</button>
        </div>
        <p class="hint" id="wa-sub" style="margin:0"></p>
        <div class="wa-list" id="wa-list"></div>
    </div>
</div>

<datalist id="tgt-cats">
    <option value="SHOLAT WAJIB"></option>
    <option value="SHOLAT SUNNAH"></option>
    <option value="PUASA SUNNAH"></option>
    <option value="TILAWAH"></option>
    <option value="SEDEKAH"></option>
    <option value="ALMATSURAT"></option>
    <option value="ISTIGHFAR"></option>
</datalist>

<section class="panel">
    <h2>Buat Grup Baru</h2>
    <div class="row">
        <input type="text" id="g-name" placeholder="Nama grup, misal: FTI">
        <button data-act="create_group" data-from="g-name">Buat Grup</button>
    </div>
    <p class="hint">Folder dibuat sebagai <code>&lt;root&gt;/&lt;nama&gt;/</code> beserta <code>index.php</code>.
       <code>target_settings.json</code> hanya dibuat saat Anda menyimpan target dari editor
       "Target &amp; analisis" (jadi grup baru tidak punya file target yang tidak diperlukan).</p>
</section>

<?php foreach ($groups as $g):
    $mentes = adm_mentees($root . '/' . $g);
    $mentor = null;
    foreach ($mentes as $m) { if ($m['isMentor']) { $mentor = $m['name']; break; } }
    $targets   = adm_read_targets($root, $g);
    $ruleCount = is_array($targets['rules'] ?? null) ? count($targets['rules']) : 0;
?>
<section class="panel">
    <h2 style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <a href="../<?= adm_h($g) ?>/index.php" style="color:var(--text);text-decoration:none"><?= adm_h($g) ?></a>
        <span class="tag muted"><?= count($mentes) ?> mentee</span>
        <?php if ($mentor): ?><span class="tag mentor">mentor: <?= adm_h($mentor) ?></span><?php endif; ?>
        <span style="flex:1"></span>
        <button class="ghost small" type="button" data-copy-wa="<?= adm_h($g) ?>"
                data-group="<?= adm_h($g) ?>" data-origin="<?= adm_h(SITE_ORIGIN) ?>"
                data-mentees="<?= adm_h(json_encode(array_keys($mentes), JSON_UNESCAPED_UNICODE)) ?>">Salin pesan</button>
        <button class="warn small" data-act="trash_group" data-group="<?= adm_h($g) ?>"
                data-confirm="Hapus grup &quot;<?= adm_h($g) ?>&quot; beserta <?= count($mentes) ?> mentee di dalamnya? Grup akan dipindahkan ke tempat sampah.">Hapus grup</button>
    </h2>
    <div class="row" style="margin-bottom:14px">
        <input type="text" id="m-<?= adm_h($g) ?>" placeholder="Nama mentee baru di grup <?= adm_h($g) ?>">
        <button data-act="create_mentee" data-group="<?= adm_h($g) ?>" data-from="m-<?= adm_h($g) ?>">Tambah Mentee</button>
    </div>

    <?php
        $gManifestUrl    = adm_group_manifest_raw_url($g);
        $gManifestLinked = str_contains((string) @file_get_contents($root . '/' . $g . '/index.php'), $gManifestUrl);
    ?>
    <div class="row" style="margin-bottom:14px;align-items:center;flex-wrap:wrap;gap:8px">
        <span class="hint" style="margin:0">Manifest grup</span>
        <?= $gManifestLinked
            ? '<span class="tag mentee">index ✓</span>'
            : '<span class="tag trash">index belum diarahkan</span>' ?>
        <button class="ghost" data-act="publish_group" data-group="<?= adm_h($g) ?>">Tulis manifest grup</button>
        <a class="tag muted" href="<?= adm_h($gManifestUrl) ?>" target="_blank" rel="noopener"><?= adm_h(adm_group_manifest_filename($g)) ?></a>
    </div>

    <?php if (!$mentes): ?>
        <div class="empty">Belum ada mentee di grup ini.</div>
    <?php else: ?>
    <table>
        <thead><tr><th>Mentee</th><th>Data</th><th>Status</th><th style="width:1%">Mentor</th><th>Manifest</th><th style="width:1%"></th></tr></thead>
        <tbody>
        <?php foreach ($mentes as $name => $m):
            $mLinked = adm_manifest_raw_url($name);
            $mInIndex = str_contains((string) @file_get_contents($root . '/' . $g . '/' . $name . '/index.php'), $mLinked);
        ?>
            <tr>
                <td><a href="../<?= adm_h($g) ?>/<?= adm_h($name) ?>/index.php" style="color:var(--text);text-decoration:none"><?= adm_h($name) ?></a></td>
                <td><?= $m['days'] ?> hari</td>
                <td>
                    <?php if ($m['isMentor']): ?><span class="tag mentor">Mentor</span>
                    <?php else: ?><span class="tag mentee">Mentee</span><?php endif; ?>
                    <?php if (!$m['hasIndex']): ?><span class="tag trash">index hilang</span><?php endif; ?>
                </td>
                <td><input type="checkbox" data-act="toggle_mentor" data-group="<?= adm_h($g) ?>" data-name="<?= adm_h($name) ?>" <?= $m['isMentor'] ? 'checked' : '' ?>></td>
                <td>
                    <?= $mInIndex ? '<span class="tag mentee">index ✓</span>' : '<span class="tag muted">index belum</span>' ?>
                    <button class="ghost" style="padding:5px 10px;font-size:.78rem;margin-top:4px"
                        data-act="gh_publish" data-group="<?= adm_h($g) ?>" data-name="<?= adm_h($name) ?>">Tulis ke GitHub</button>
                    <button class="ghost" style="padding:5px 10px;font-size:.78rem"
                        data-act="apply_manifest_link" data-group="<?= adm_h($g) ?>" data-name="<?= adm_h($name) ?>">Arahkan index</button>
                </td>
                <td><button class="warn" data-act="trash_mentee" data-group="<?= adm_h($g) ?>" data-name="<?= adm_h($name) ?>">Hapus</button></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <details class="tgt" data-group="<?= adm_h($g) ?>">
        <summary>Target &amp; analisis <code>target_settings.json</code>
            <span class="tag <?= $ruleCount ? 'mentee' : 'muted' ?>"><?= $ruleCount ?> aturan</span>
            <?php if (!is_file(adm_target_file($root, $g))): ?><span class="tag trash">file belum ada</span><?php endif; ?>
        </summary>
        <script type="application/json" class="tgt-data"><?= adm_targets_json($targets) ?></script>
        <div class="tgt-body"></div>
    </details>
</section>
<?php endforeach; ?>

<details class="panel">
    <summary>Tempat Sampah <span class="tag trash"><?= count($trashAll) + count($outMentees) ?> item</span></summary>
    <div class="panel-body">
    <p class="hint">Grup di bawah tidak aktif di root; menteenya masih tersimpan dan bisa dipulihkan.
       Saat menambah mentee dengan nama yang sama, folder di sini otomatis dipulihkan.</p>

    <?php if ($trashAll): ?>
        <h3 style="font-size:.95rem;margin:16px 0 8px;color:var(--muted)">Grup di tempat sampah</h3>
        <?php foreach ($trashAll as $tg): $retired = !in_array($tg, $groups, true); $mentees = adm_mentees($trashRoot . '/' . $tg); ?>
            <div style="border:1px solid var(--line);border-radius:12px;padding:10px 12px;margin:10px 0;background:rgba(255,255,255,.015)">
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:6px">
                    <strong><?= adm_h($tg) ?></strong>
                    <?php if ($retired): ?>
                        <span class="tag trash">grup tidak aktif</span>
                        <button class="ghost" data-act="restore_group" data-group="<?= adm_h($tg) ?>">Pulihkan grup</button>
                    <?php else: ?>
                        <span class="tag muted">grup aktif</span>
                    <?php endif; ?>
                </div>
                <?php if (!$mentees): ?>
                    <div class="hint" style="margin:0">Tidak ada mentee di grup ini.</div>
                <?php else: ?>
                    <table>
                        <tbody>
                        <?php foreach ($mentees as $name => $m): ?>
                            <tr>
                                <td><?= adm_h($name) ?></td>
                                <td><?= $m['days'] ?> hari</td>
                                <td><?= $m['isMentor'] ? '<span class="tag mentor">Mentor</span>' : '' ?></td>
                                <td style="width:1%;white-space:nowrap">
                                    <button class="ghost" data-act="restore_mentee" data-group="<?= adm_h($tg) ?>" data-name="<?= adm_h($name) ?>">Pulihkan</button>
                                    <button class="ghost" data-act="retire_mentee" data-group="<?= adm_h($tg) ?>" data-name="<?= adm_h($name) ?>"
                                            data-confirm="Tandai &quot;<?= adm_h($name) ?>&quot; sebagai tidak lagi mentoring?">Tidak lagi mentoring</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($outMentees): $oi = 0; ?>
        <h3 style="font-size:.95rem;margin:16px 0 8px;color:var(--out)">Tidak lagi mentoring</h3>
        <p class="hint">Mentee ini sudah tidak lagi mentoring. Pilih grup tujuan (aktif atau di tempat sampah),
           lalu klik <strong>Aktif kembali</strong>.</p>
        <table>
            <tbody>
            <?php foreach ($outMentees as $name => $m): $selId = 'out-target-' . $oi++; ?>
                <tr class="out">
                    <td><span class="out-name"><?= adm_h($name) ?></span> <span class="tag out">tidak lagi mentoring</span></td>
                    <td><?= $m['days'] ?> hari</td>
                    <td>
                        <select id="<?= $selId ?>" aria-label="Grup tujuan">
                            <?php foreach ($allGroups as $g): ?>
                                <option value="<?= adm_h($g) ?>"><?= adm_h($g) ?><?= in_array($g, $groups, true) ? '' : ' (tidak aktif)' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td style="width:1%">
                        <button class="ghost" data-act="reactivate_mentee" data-name="<?= adm_h($name) ?>"
                                data-target-from="<?= $selId ?>">Aktif kembali</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (!$trashAll && !$outMentees): ?>
        <div class="empty">Tempat sampah kosong.</div>
    <?php endif; ?>
    </div>
</details>

<section class="panel">
    <h2>Manifest PWA &rarr; GitHub</h2>
    <p class="hint">
        Repo khusus manifest: <code><?= adm_h(MANIFEST_REPO) ?></code> (branch <code><?= adm_h(MANIFEST_BRANCH) ?></code>).
        Aplikasi membacanya lewat <code>raw.githubusercontent.com</code>, jadi repo harus <strong>publik</strong>.
    </p>

    <h3 style="font-size:.95rem;margin:18px 0 8px;color:var(--muted)">1. Token GitHub</h3>
    <?php if (adm_gh_token() !== ''): ?>
        <p class="hint">Token tersimpan di <code>test/_manifest/.token</code> (mode 600, tidak ikut ke git/server).</p>
        <div class="row" style="margin:10px 0">
            <button class="ghost" data-act="test_gh">Uji koneksi</button>
            <button class="ghost" data-act="gh_list">Daftar file di repo</button>
            <button class="warn" data-act="set_token" data-empty="1">Hapus token</button>
        </div>
    <?php else: ?>
        <p class="hint">Belum ada token. Buat <em>Fine-grained personal access token</em> di GitHub
          (<code>Settings &rsaquo; Developer settings &rsaquo; Personal access tokens &rsaquo; Fine-grained tokens</code>),
          beri akses <strong>Contents: Read and write</strong> pada repo
          <code><?= adm_h(MANIFEST_REPO) ?></code>. Token berawalan <code>github_pat_</code>.</p>
        <div class="row" style="margin:10px 0">
            <input type="text" id="gh-token" placeholder="github_pat_..." autocomplete="off" spellcheck="false">
            <button data-act="set_token" data-from="gh-token">Simpan token</button>
        </div>
    <?php endif; ?>

    <h3 style="font-size:.95rem;margin:18px 0 8px;color:var(--muted)">2. Tulis manifest</h3>
    <div class="row" style="margin:10px 0">
        <button data-act="gh_publish_all">Tulis SEMUA manifest ke repo</button>
        <code style="font-size:.8rem"><?= count(adm_local_manifests()) ?> salinan lokal di test/_manifest/</code>
    </div>
    <p class="hint">Per-bar: pakai tombol <strong>Tulis ke GitHub</strong> di tabel mentee.
       Tombol <strong>Arahkan index</strong> menuliskan raw URL ke <code>index.php</code> mentee.
       Untuk halaman dasbor grup, pakai tombol <strong>Tulis manifest grup</strong> di tiap panel grup
       (menulis <code>manifest_&lt;grup&gt;.json</code> sekaligus mengarahkan <code>index.php</code> grup).</p>

    <h3 style="font-size:.95rem;margin:18px 0 8px;color:var(--muted)">3. Isi repo (dibaca dari GitHub)</h3>
    <div id="gh-files"><p class="hint">Klik <strong>Daftar file di repo</strong> untuk memuat isi repository.</p></div>

    <h3 style="font-size:.95rem;margin:18px 0 8px;color:var(--muted)">Catatan</h3>
    <ul class="hint" style="padding-left:20px;margin:8px 0">
        <li>Raw URL punya cache ±2 menit setelah commit. Untuk jenama ulang: hapus PWA dari layar utama, lalu buka lagi.</li>
        <li>Manifest ditulis ulang tiap kali tombol ditekan. Nilai yang diubah manual di GitHub akan tertimpa.</li>
        <li>Semua file yang tidak diawali <code>manifest_</code> di repo itu akan diabaikan dan tidak disentuh.</li>
        <li><strong>Tulis SEMUA manifest</strong> mencakup manifest tiap mentee <em>dan</em> manifest dasbor tiap grup.</li>
    </ul>
</section>

<?php nickname_render($root); ?>
</div>

<script>
const msgs = document.getElementById('msgs');
function show(list){
    msgs.innerHTML = '';
    (list||[]).forEach(m=>{
        const li = document.createElement('li');
        li.className = m.type;
        li.textContent = m.text;
        msgs.appendChild(li);
    });
}
document.querySelectorAll('[data-act]').forEach(el=>{
    // Checkbox/select/input pakai 'change' saja. Sebelumnya keduanya dipasang,
    // sehingga checkbox (mis. toggle mentor) mengirim aksi DUA kali dan hasilnya
    // kembali ke keadaan semula. Tombol tetap memakai 'click'.
    if (el.matches('input, select, textarea')){
        el.addEventListener('change', e => doAction(e.target));
    } else {
        el.addEventListener('click', e => doAction(e.target));
    }
});
function doAction(el){
    if (el.dataset.confirm && !window.confirm(el.dataset.confirm)) return;

    const body = new URLSearchParams();
    body.set('action', el.dataset.act);
    if (el.dataset.group) body.set('group', el.dataset.group);
    if (el.dataset.name)  body.set('name', el.dataset.name);
    if (el.dataset.targetFrom){
        const sel = document.getElementById(el.dataset.targetFrom);
        if (sel && sel.value) body.set('target', sel.value);
    }

    if (el.dataset.from){
        const input = document.getElementById(el.dataset.from);
        const v = input.value.trim();
        if (!v){ show([{type:'err', text:'Nama tidak boleh kosong.'}]); return; }
        if (el.dataset.act === 'create_group') body.set('name', v);
        else body.set('name', v);
        input.value = '';
    }

    fetch('', {method:'POST', body, headers:{'Content-Type':'application/x-www-form-urlencoded'}})
        .then(r => r.json())
        .then(d => {
            show(d.messages);
            // Muat ulang supaya tabel ikut berubah
            setTimeout(() => location.reload(), d.ok ? 700 : 200);
        })
        .catch(() => show([{type:'err', text:'Gagal menghubungi server.'}]));
}

// ===================== Editor target_settings.json =====================
(function(){
    const MODES = [
        ['day_presence','day_presence — harian terisi'],
        ['value_count','value_count — hitung nilai tertentu'],
        ['filled_key_count','filled_key_count — hitung key terisi'],
        ['rawatib_count','rawatib_count — jumlah rakaat'],
        ['numeric','numeric — ambang angka'],
        ['quran_sheet_count','quran_sheet_count — lembar Al-Quran'],
    ];
    const PERIODS = [['day','per hari'],['week','per pekan'],['month','per bulan']];
    const MODE_FIELDS = {
        day_presence:['logic'],
        value_count:['match_value'],
        filled_key_count:['logic'],
        rawatib_count:['rakaat_per_check'],
        numeric:['threshold'],
        quran_sheet_count:['pages_per_sheet','source_dataset'],
    };
    const ALL_KEYS = ['subuh','dzuhur','ashar','maghrib','isya','rawatib','dhuha','tahajud',
                      'senin_kamis','ayamul_bidh','tilawah','sedekah','almatsurat_pagi','almatsurat_petang','istighfar'];

    function mk(tag, props, ...kids){
        const n = document.createElement(tag);
        if (props) for (const k in props){
            const v = props[k];
            if (v == null) continue;
            if (k === 'class') n.className = v;
            else if (k === 'text') n.textContent = v;
            else if (k === 'value') n.value = v;
            else if (k.startsWith('on')) n.addEventListener(k.slice(2).toLowerCase(), v);
            else n.setAttribute(k, v);
        }
        kids.flat().forEach(c => { if (c == null) return; n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
        return n;
    }
    function field(label, el, hint){
        return mk('div',{class:'tgt-field'}, mk('label',{text:label}), el, hint ? mk('small',{text:hint}) : null);
    }
    function inp(type, value, props){
        return mk('input', Object.assign({type:type, value: value == null ? '' : value}, props||{}));
    }
    function sel(options, value){
        const s = mk('select');
        options.forEach(([v,t]) => s.appendChild(mk('option',{value:v, text:t})));
        s.value = value;
        if (s.selectedIndex < 0) s.selectedIndex = 0;
        return s;
    }

    function ruleCard(rule){
        const card = mk('div',{class:'tgt-rule'});
        const title = mk('strong',{text: rule.label || 'Aturan baru'});
        card.appendChild(mk('div',{class:'tgt-rule-head'},
            title,
            mk('button',{class:'warn small', type:'button', onclick(){ card.remove(); }}, 'Hapus')
        ));

        const f = {};
        f.category = inp('text', rule.category||'', {list:'tgt-cats', placeholder:'SHOLAT WAJIB'});
        f.label    = inp('text', rule.label||'');
        f.target   = inp('text', rule.target||'', {placeholder:'3 kali per hari'});
        f.period   = sel(PERIODS, rule.period||'day');
        f.target_count = inp('number', rule.target_count != null ? rule.target_count : 1, {min:'0', step:'1'});
        f.mode     = sel(MODES, rule.mode||'day_presence');
        f.label.addEventListener('input', () => { title.textContent = f.label.value.trim() || 'Aturan baru'; });

        card.appendChild(mk('div',{class:'tgt-grid'},
            field('Kategori', f.category),
            field('Label', f.label),
            field('Target (teks)', f.target),
            field('Periode', f.period),
            field('Jumlah target', f.target_count),
            field('Mode hitung', f.mode)
        ));

        // Field kondisional dibuat sekali, lalu ditampilkan sesuai mode.
        const condEls = {
            match_value:      field('Nilai dicocokkan (match_value)', inp('text', rule.match_value||'', {placeholder:'M'})),
            rakaat_per_check: field('Rakaat per centang', inp('number', rule.rakaat_per_check != null ? rule.rakaat_per_check : 2, {min:'1', step:'1'})),
            threshold:        field('Ambang (threshold)', inp('number', rule.threshold != null ? rule.threshold : (rule.target_count || 1), {min:'0'})),
            pages_per_sheet:  field('Halaman per lembar', inp('number', rule.pages_per_sheet != null ? rule.pages_per_sheet : 2, {min:'1'})),
            source_dataset:   field('Sumber dataset', inp('text', rule.source_dataset||'', {placeholder:'opsional'})),
            logic:            field('Logika', sel([['all','semua key'],['any','salah satu']], rule.logic||'all'))
        };
        const condBox = mk('div',{class:'tgt-grid'});
        function renderCond(){
            condBox.innerHTML = '';
            (MODE_FIELDS[f.mode.value]||[]).forEach(k => condBox.appendChild(condEls[k]));
        }
        f.mode.addEventListener('change', renderCond);
        renderCond();
        card.appendChild(condBox);

        const keysEl = inp('text', (rule.data_keys||[]).join(', '));
        card.appendChild(field('Data keys (pisahkan koma)', keysEl, 'Pilihan: ' + ALL_KEYS.join(', ')));

        const detailEl = mk('textarea'); detailEl.value = rule.detail || '';
        card.appendChild(field('Detail', detailEl));

        card._read = () => {
            const m = f.mode.value;
            const out = {
                category: f.category.value.trim(),
                label: f.label.value.trim(),
                target: f.target.value.trim(),
                period: f.period.value,
                target_count: Number(f.target_count.value) || 0,
                mode: m,
                detail: detailEl.value.trim(),
                data_keys: keysEl.value.split(',').map(s => s.trim()).filter(Boolean),
            };
            (MODE_FIELDS[m]||[]).forEach(k => {
                const el = condEls[k].querySelector('input, select, textarea');
                if (!el) return;
                out[k] = (el.tagName === 'SELECT') ? el.value
                       : (el.type === 'number' ? (Number(el.value) || 0) : el.value.trim());
            });
            return out;
        };
        return card;
    }

    function build(root){
        const script = root.querySelector('.tgt-data');
        let data = {};
        try { data = JSON.parse(script.textContent) || {}; } catch (e) { data = {}; }

        const body = root.querySelector('.tgt-body');
        body.innerHTML = '';

        const tf = {
            group:    inp('text', data.group||''),
            tag:      inp('text', data.tag||''),
            title:    inp('text', data.title||''),
            greeting: inp('text', data.greeting||''),
            intro:    inp('text', data.intro||''),
            closing:  inp('text', data.closing||''),
        };
        body.appendChild(mk('div',{class:'tgt-grid'},
            field('Group', tf.group), field('Tag', tf.tag),
            field('Judul', tf.title), field('Sapaan', tf.greeting)
        ));
        body.appendChild(mk('div',{class:'tgt-grid'},
            field('Intro', tf.intro), field('Closing', tf.closing)
        ));

        body.appendChild(mk('hr',{class:'tgt-sep'}));
        body.appendChild(mk('div',{class:'tgt-note', text:'Aturan target (dipakai halaman analisis grup):'}));

        const list = mk('div');
        body.appendChild(list);
        (Array.isArray(data.rules) ? data.rules : []).forEach(r => list.appendChild(ruleCard(r)));

        function save(){
            const settings = {
                group: tf.group.value.trim(),
                title: tf.title.value.trim(),
                greeting: tf.greeting.value.trim(),
                intro: tf.intro.value.trim(),
                closing: tf.closing.value.trim(),
                tag: tf.tag.value.trim(),
                rules: Array.from(list.querySelectorAll('.tgt-rule'))
                    .map(c => c._read())
                    .filter(r => r.label || r.category || (r.data_keys && r.data_keys.length)),
            };
            const post = new URLSearchParams();
            post.set('action','save_targets');
            post.set('group', root.dataset.group);
            post.set('settings', JSON.stringify(settings));
            fetch('', {method:'POST', body:post, headers:{'Content-Type':'application/x-www-form-urlencoded'}})
                .then(r => r.json())
                .then(d => { show(d.messages); setTimeout(() => location.reload(), d.ok ? 700 : 300); })
                .catch(() => show([{type:'err', text:'Gagal menghubungi server.'}]));
        }

        body.appendChild(mk('div',{class:'tgt-actions'},
            mk('button',{class:'ghost', type:'button', onclick(){ list.appendChild(ruleCard({})); }}, '+ Tambah aturan'),
            mk('button',{type:'button', onclick: save}, 'Simpan target'),
            mk('span',{class:'tgt-note', text:'File: ' + root.dataset.group + '/target_settings.json'})
        ));
    }

    document.querySelectorAll('details.tgt').forEach(build);
})();

// ============ Salin pesan WhatsApp (popup pilih mentee) ============
(function(){
    const modal = document.getElementById('wa-modal');
    if (!modal) return;
    const listEl  = modal.querySelector('#wa-list');
    const subEl   = modal.querySelector('#wa-sub');
    const titleEl = modal.querySelector('#wa-title');
    let current = null; // { group, origin }

    function close(){ modal.hidden = true; listEl.innerHTML = ''; current = null; }

    // Pesan WhatsApp: codename grup & mentee (bold + code) + URL lengkap mentee.
    // WhatsApp: *bold*, ```code``` (monospace). Digabung: *```teks```*
    function message(group, name, origin){
        const url = String(origin || location.origin).replace(/\/+$/, '') + '/' + group + '/' + name + '/index.php';
        return "Assalamu'alaikum warahmatullahi wabarakatuh\n"
             + '\n'
             + 'Berikut tautan halaman amalan yaumiyah antum untuk mencatat amalan harian.\n'
             + '\n'
             + 'Amalan Yaumiyah\n'
             + 'Grup   : *```' + group + '```*\n'
             + 'Mentee : *```' + name + '```*\n'
             + 'Link   : ' + url + '\n'
             + '\n'
             + 'Barakallahu fiik.\n'
             + "Wassalamu'alaikum warahmatullahi wabarakatuh\n"
             + '\n'
             + '———\n'
             + 'Web refleksi untuk mentee: https://refleksiformentee.xo.je';
    }

    function copyText(text){
        if (navigator.clipboard && navigator.clipboard.writeText){
            return navigator.clipboard.writeText(text);
        }
        const ta = document.createElement('textarea');
        ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
        document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); } catch(_){}
        ta.remove();
        return Promise.resolve();
    }

    modal.addEventListener('click', e => {
        if (e.target === modal || e.target.closest('.wa-close')) close();
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !modal.hidden) close();
    });

    document.querySelectorAll('[data-copy-wa]').forEach(btn => {
        btn.addEventListener('click', () => {
            const group = btn.dataset.group || btn.dataset.copyWa || '';
            const origin = btn.dataset.origin || location.origin;
            let items = [];
            try { items = JSON.parse(btn.dataset.mentees || '[]'); } catch(_) { items = []; }

            current = { group, origin };
            titleEl.textContent = 'Salin pesan \u2014 grup ' + group;
            listEl.innerHTML = '';

            if (!items.length){
                subEl.textContent = 'Belum ada mentee di grup ini.';
            } else {
                subEl.textContent = 'Pilih mentee yang ingin disalin:';
                items.forEach(name => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'wa-item';
                    b.dataset.name = name;
                    b.textContent = name;
                    listEl.appendChild(b);
                });
            }
            modal.hidden = false;
        });
    });

    listEl.addEventListener('click', e => {
        const b = e.target.closest('.wa-item');
        if (!b || !current) return;
        const name = b.dataset.name || '';
        const text = message(current.group, name, current.origin);
        const done = () => {
            b.classList.add('copied');
            b.textContent = name + ' \u2713 tersalin';
            show([{ type: 'ok', text: 'Pesan WhatsApp untuk \'' + name + '\' tersalin ke clipboard.' }]);
            setTimeout(close, 900);
        };
        copyText(text).then(done).catch(done);
    });
})();
</script>
</body>
</html>
