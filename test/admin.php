<?php
// FILE: test/admin.php
// Halaman admin untuk mengelola grup mentoring dan mentee.
//
// Aturan struktur folder:
//   <root>/<grup>/index.php          -> halaman grup (wajib ada)
//   <root>/<grup>/target_settings.json
//   <root>/test/bi/target_settings.json    -> target khusus mode "semua kelompok"
//                                             (berdiri sendiri, bukan milik grup)
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
//   Format yang diterima: fine-grained (github_pat_...) dengan repo manifest
//   dipilih + izin "Contents: Read and write", atau classic (ghp_...) scope repo.

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
    // Dibaca lewat adm_secret_read_file() agar otomatis mendekripsi bila berkas
    // disimpan sebagai amplop AES-256-GCM (lihat panel "Keamanan rahasia").
    return adm_secret_read_file(GITHUB_TOKEN_FILE);
}

/**
 * Jenis token tersimpan: 'fine' (fine-grained, awalan github_pat_), 'classic'
 * (ghp_/gho_/ghu_/ghs_/ghr_), 'lain' (tidak dikenali), atau '' (belum ada).
 */
function adm_gh_token_kind(): string {
    $t = adm_gh_token();
    if ($t === '') return '';
    if (str_starts_with($t, 'github_pat_')) return 'fine';
    if (preg_match('/^gh[pousr]_/', $t))    return 'classic';
    return 'lain';
}

/** Benar bila teks cocok dengan format token GitHub (fine-grained atau classic). */
function adm_gh_token_format_ok(string $token): bool {
    // Fine-grained: github_pat_<22 chars>_<59 chars> (boleh ada '_' di tengah).
    if (preg_match('/^github_pat_[A-Za-z0-9_]{20,255}$/', $token)) return true;
    // Classic: ghp_/gho_/ghu_/ghs_/ghr_ diikuti alfanumerik.
    if (preg_match('/^gh[pousr]_[A-Za-z0-9]{20,255}$/', $token)) return true;
    return false;
}

function adm_gh_token_set(string $token): array {
    if (!is_dir(MANIFEST_LOCAL_DIR)) mkdir(MANIFEST_LOCAL_DIR, 0755, true);
    $token = trim($token);

    if ($token === '') {
        if (is_file(GITHUB_TOKEN_FILE)) @unlink(GITHUB_TOKEN_FILE);
        return ['ok' => true, 'msg' => 'Token dihapus.'];
    }
    if (!adm_gh_token_format_ok($token)) {
        return ['ok' => false, 'msg' => 'Format token tidak dikenali. Pakai fine-grained (awalan github_pat_) atau classic (awalan ghp_).'];
    }
    $kind = str_starts_with($token, 'github_pat_') ? 'fine-grained' : 'classic';

    // Simpan sementara supaya adm_gh_api() bisa memakainya untuk verifikasi.
    if (!adm_secret_write_file(GITHUB_TOKEN_FILE, $token)) {
        return ['ok' => false, 'msg' => 'Gagal menulis file token. Periksa hak akses folder test/_manifest/.'];
    }

    // Verifikasi nyata: token HARUS bisa membaca repo manifest. Untuk fine-grained
    // ini sekaligus memastikan repo sudah dipilih saat token dibuat.
    $repo = adm_gh_api('GET', 'https://api.github.com/repos/' . MANIFEST_REPO);
    if (!$repo['ok']) {
        @unlink(GITHUB_TOKEN_FILE);
        $hint = $kind === 'fine-grained'
            ? ' Untuk fine-grained token: pilih "Only select repositories" lalu centang ' . MANIFEST_REPO
              . ', dan beri izin "Contents: Read and write".'
            : '';
        return ['ok' => false, 'msg' => "Token $kind tidak bisa mengakses " . MANIFEST_REPO . ': ' . $repo['error'] . $hint];
    }

    // Nama akun bersifat opsional: sebagian token fine-grained membatasi /user,
    // jadi kegagalan di sini TIDAK dianggap gagal menyimpan.
    $login = '';
    $me = adm_gh_api('GET', 'https://api.github.com/user');
    if ($me['ok']) $login = (string) ($me['data']['login'] ?? '');
    $who = $login !== '' ? "@$login" : 'token ini';

    // Cek izin tulis bila GitHub memberitahukannya.
    $perm = is_array($repo['data']['permissions'] ?? null) ? $repo['data']['permissions'] : null;
    $sec  = adm_secret_key() !== null ? ' (terenkripsi)' : '';
    if ($perm === null) {
        return ['ok' => true, 'msg' => "Token $kind tersimpan & terverifikasi ($who). Akses ke " . MANIFEST_REPO . " OK$sec."];
    }
    if (empty($perm['push'])) {
        return ['ok' => true, 'msg' => "Token $kind tersimpan & terverifikasi ($who). Peringatan: GitHub belum menandai izin tulis ke "
            . MANIFEST_REPO . " — pastikan \"Contents: Read and write\" agar manifest bisa ditulis$sec."];
    }
    return ['ok' => true, 'msg' => "Token $kind tersimpan & terverifikasi ($who). Akses baca+tulis ke " . MANIFEST_REPO . " OK$sec."];
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
        $raw = (string) ($data['message'] ?? ('HTTP ' . $code));
        $noAcc = stripos($raw, 'not accessible by personal access token') !== false;
        $msg = $raw;
        if ($code === 401) {
            $msg = 'Token ditolak atau kedaluwarsa.';
        } elseif ($code === 403) {
            $msg = $noAcc
                ? 'Token tidak punya izin ke resource ini. Untuk fine-grained token: pilih repo ' . MANIFEST_REPO . ' dan beri "Contents: Read and write".'
                : 'Akses ditolak. Cek izin token (butuh "Contents: Read and write").';
        } elseif ($code === 404) {
            $extra = $noAcc ? '' : ' (Untuk fine-grained token: pastikan repo ' . MANIFEST_REPO . ' sudah dipilih & diberi izin.)';
            $msg = 'Repo/file tidak ditemukan. Cek repo & branch.' . $extra;
        } elseif ($code === 409) {
            $msg = 'Konflik SHA - file berubah, muat ulang lalu coba lagi.';
        }
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
    adm_prune_local_manifests();

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
 * Manifest lokal yang selalu dipertahankan walau tidak terkait grup/mentee mana pun.
 * `manifest_test.json` diurus terpisah (untuk halaman test/), bukan oleh admin.php.
 */
const MANIFEST_KEEP = ['manifest_test.json'];

/**
 * Hapus salinan lokal manifest yang sudah tidak dipakai, supaya folder
 * test/_manifest/ tidak menumpuk sisa mentee/grup yang sudah dihapus.
 * Yang dipertahankan: manifest tiap grup aktif di root + manifest tiap menteenya,
 * plus MANIFEST_KEEP. File .token tidak pernah disentuh (bukan pola manifest_*.json).
 */
function adm_prune_local_manifests(): void {
    $have = adm_local_manifests();
    if (!$have) return;

    $expect = array_fill_keys(MANIFEST_KEEP, true);
    foreach (adm_groups(ADMIN_ROOT) as $g) {
        $expect[adm_group_manifest_filename($g)] = true;
        foreach (adm_mentees(ADMIN_ROOT . '/' . $g) as $m) {
            $expect[adm_manifest_filename($m['name'])] = true;
        }
    }

    foreach ($have as $f) {
        if (!isset($expect[$f['file']])) {
            @unlink(MANIFEST_LOCAL_DIR . '/' . $f['file']);
        }
    }
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
    adm_prune_local_manifests();

    return ['ok' => true, 'file' => $file, 'url' => adm_group_manifest_raw_url($group), 'json' => $json . "\n"];
}

// ======================= TARGET SETTINGS (target_settings.json) =======================
// Konfigurasi target per grup, dibaca test/bi.php untuk menghitung skor analisis.
// Ditulis admin.php agar tidak lagi bergantung pada file manual yang ter-ignore.

/** Path file target settings sebuah grup. */
function adm_target_file(string $root, string $group): string {
    return $root . '/' . $group . '/target_settings.json';
}

/**
 * Berkas aturan target khusus mode "semua kelompok" (lintas grup). Namanya
 * tetap target_settings.json, tapi diletakkan di folder halaman analisis
 * lintas grup (test/bi/) -- sengaja TIDAK memakai target_settings.json
 * milik grup mana pun.
 */
function adm_cross_target_file(): string {
    return __DIR__ . '/bi/target_settings.json';
}

/** Baca target_settings.json sebuah grup. Kembalikan [] bila tidak ada/rusak. */
function adm_read_targets(string $root, string $group): array {
    return adm_read_targets_file(adm_target_file($root, $group));
}

/** Baca data target dari sebuah berkas. Kembalikan [] bila tidak ada/rusak. */
function adm_read_targets_file(string $file): array {
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

/**
 * Kumpulan kunci berdasarkan tipe nilainya. Dipakai bersama validator agar
 * aturan yang mustahil tercapai (capaian selalu 0) tertangkap lebih awal.
 */
function adm_key_value_groups(): array {
    return [
        // Nilainya "✓" (centang).
        'checkbox' => ['dhuha','tahajud','witir','senin_kamis','ayamul_bidh','sedekah','almatsurat_pagi','almatsurat_petang'],
        // Kode kehadiran sholat wajib.
        'sholat'   => ['subuh','dzuhur','ashar','maghrib','isya'],
        // Satu-satunya kunci yang nilainya angka.
        'numeric'  => ['istighfar'],
    ];
}

/**
 * Periksa apakah sebuah aturan masuk akal / mungkin tercapai. Mencegah aturan
 * yang selalu 0% karena salah nilai atau kombinasi mode/kunci/periode yang
 * mustahil (mis. mode numeric untuk kunci centang, atau day_presence dengan
 * target lebih dari 1 per hari).
 *
 * @return string[] daftar masalah (kosong bila tidak ada).
 */
function adm_target_rule_problems(array $rule): array {
    $problems = [];
    $mode     = strtolower((string) ($rule['mode'] ?? 'day_presence'));
    $period   = strtolower((string) ($rule['period'] ?? 'day'));
    $count    = is_numeric($rule['target_count'] ?? null) ? (float) $rule['target_count'] : 0.0;

    $dkRaw = is_array($rule['data_keys'] ?? null) ? $rule['data_keys'] : [];
    $dk    = array_values(array_filter(array_map(static fn($k) => is_string($k) ? trim($k) : '', $dkRaw), static fn($k) => $k !== ''));

    $groups       = adm_key_value_groups();
    $checkboxKeys = $groups['checkbox'];
    $sholatKeys   = $groups['sholat'];
    $numericKeys  = $groups['numeric'];

    $mv     = is_scalar($rule['match_value'] ?? null) ? trim((string) $rule['match_value']) : '';
    $tokens = array_values(array_filter(array_map('trim', explode(',', $mv)), static fn($s) => $s !== ''));

    // 1. value_count: match_value harus cocok dengan nilai yang tersimpan.
    if ($mode === 'value_count') {
        if ($dk !== [] && array_diff($dk, $checkboxKeys) === []) {
            foreach ($tokens as $tok) {
                if ($tok !== '✓') {
                    $problems[] = "match_value '$tok' tidak cocok untuk kunci centang (nilai tersimpan \"✓\").";
                }
            }
        } elseif ($dk !== [] && array_diff($dk, $sholatKeys) === []) {
            $validMv = ['M','R-J','M-S','R','Q'];
            foreach ($tokens as $tok) {
                if (!in_array($tok, $validMv, true)) {
                    $problems[] = "match_value '$tok' bukan kode sholat wajib yang sah (M, R-J, M-S, R, Q).";
                }
            }
        }
    }

    // 2. numeric hanya cocok untuk kunci yang nilainya angka (istighfar).
    if ($mode === 'numeric') {
        foreach ($dk as $k) {
            if (!in_array($k, $numericKeys, true)) {
                $problems[] = "mode numeric tidak cocok untuk kunci '$k' (nilainya bukan angka). Kunci angka: " . implode(', ', $numericKeys) . ".";
            }
        }
    }

    // 3. day_presence maksimal 1 kejadian per hari.
    if ($mode === 'day_presence' && $period === 'day' && $count > 1) {
        $problems[] = "target_count $count tidak mungkin untuk day_presence periode harian (maksimal 1 per hari). Untuk target berulang, gunakan periode 'week' atau 'month'.";
    }

    // 4. filled_key_count tidak bisa melebihi jumlah kunci per hari.
    if ($mode === 'filled_key_count' && $period === 'day' && $dk !== [] && $count > count($dk)) {
        $problems[] = "target_count $count melebihi jumlah data_keys (" . count($dk) . ") untuk filled_key_count periode harian.";
    }

    // 5. quran_sheet_count memakai kunci tilawah.
    if ($mode === 'quran_sheet_count' && $dk !== [] && !in_array('tilawah', $dk, true)) {
        $problems[] = "mode quran_sheet_count biasanya memakai kunci 'tilawah'.";
    }

    return $problems;
}

/** Validasi struktur target_settings.json sebelum disimpan.
 *  Mengembalikan ['ok'=>true] atau ['ok'=>false,'errors'=>[...]]. */
function adm_validate_target_structure(array $raw, string $group): array {
    $errors = [];
    $rules  = $raw['rules'] ?? null;
    if (!is_array($rules) || $rules === []) {
        $errors[] = 'rules harus berisi minimal satu aturan.';
        return ['ok' => false, 'errors' => $errors];
    }
    $validModes   = ['day_presence', 'value_count', 'filled_key_count', 'rawatib_count', 'numeric', 'quran_sheet_count'];
    $validPeriods = ['day', 'week', 'month'];
    $validKeys    = ['subuh','dzuhur','ashar','maghrib','isya','rawatib','dhuha','tahajud','witir','senin_kamis','ayamul_bidh','tilawah','sedekah','almatsurat_pagi','almatsurat_petang','istighfar'];

    foreach ($rules as $i => $r) {
        if (!is_array($r)) { $errors[] = "Aturan #" . ($i+1) . " bukan objek."; continue; }
        $n = $i + 1;
        $lbl = $r['label'] ?? ('#' . $n);
        if (empty($r['label']))      $errors[] = "Aturan #$n ($lbl): label wajib diisi.";
        if (empty($r['category']))   $errors[] = "Aturan #$n ($lbl): category wajib diisi.";
        $mode = strtolower((string) ($r['mode'] ?? 'day_presence'));
        if (!in_array($mode, $validModes, true)) $errors[] = "Aturan #$n ($lbl): mode '$mode' tidak dikenal.";
        $period = strtolower((string) ($r['period'] ?? 'day'));
        if (!in_array($period, $validPeriods, true)) $errors[] = "Aturan #$n ($lbl): periode '$period' tidak dikenal.";
        if (!is_numeric($r['target_count'] ?? null)) $errors[] = "Aturan #$n ($lbl): target_count harus angka.";
        if ($mode === 'value_count' && empty($r['match_value'])) $errors[] = "Aturan #$n ($lbl): match_value wajib untuk mode value_count.";
        if ($mode !== 'rawatib_count' && empty($r['data_keys'])) {
            $errors[] = "Aturan #$n ($lbl): data_keys wajib untuk mode $mode.";
        } elseif (is_array($r['data_keys'] ?? null)) {
            foreach ($r['data_keys'] as $dk) {
                if (!in_array($dk, $validKeys, true)) {
                    $errors[] = "Aturan #$n ($lbl): data_keys '$dk' tidak dikenal — capaian akan selalu 0.";
                }
            }
        }
        // Tangkap kombinasi mode/kunci/periode/match_value yang mustahil
        // tercapai (capaian selalu 0) agar tidak lolos simpan.
        foreach (adm_target_rule_problems($r) as $p) {
            $errors[] = "Aturan #$n ($lbl): $p";
        }
        if ($mode === 'numeric' && !is_numeric($r['threshold'] ?? null) && !is_numeric($r['match_value'] ?? null)) {
            $errors[] = "Aturan #$n ($lbl): threshold (atau match_value) harus angka untuk mode numeric.";
        }
    }
    return ['ok' => $errors === [], 'errors' => $errors];
}

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

// ======================= AI OPENROUTER =======================
// Koneksi ke OpenRouter (mode gratis) untuk menyusun target_settings.json
// secara otomatis dari deskripsi rules. Hasil: {"coverable":true,"settings":{...}}
// atau {"coverable":false,"feedback":"..."}. Rahasia disimpan di test/_ai/
// (mode 600), tidak ikut ke git maupun ke FTP.
const AI_DIR            = __DIR__ . '/_ai';
const AI_KEY_FILE       = AI_DIR . '/openrouter_key';
const AI_GH_TOKEN_F     = AI_DIR . '/github_token';
const AI_ISSUE_REPO_F   = AI_DIR . '/issue_repo';
const AI_ISSUE_REPO     = 'krasyid822/git-ftp-amalanyumiyah';
const AI_MODELS_CACHE   = AI_DIR . '/models_cache.json';
const AI_API            = 'https://openrouter.ai/api/v1/chat/completions';
const AI_MODELS_URL     = 'https://openrouter.ai/api/v1/models';
const AI_CACHE_TTL      = 6 * 3600;
const AI_PERIODS        = ['day', 'week', 'month'];
const AI_MODES          = ['value_count', 'day_presence', 'filled_key_count', 'numeric', 'rawatib', 'quran_sheet'];
const AI_TOP_KEYS       = ['group', 'title', 'greeting', 'intro', 'closing', 'tag', 'rules'];
const AI_RULE_KEYS      = ['category', 'label', 'target', 'period', 'target_count', 'mode', 'match_value', 'logic', 'rakaat_per_check', 'threshold', 'pages_per_sheet', 'source_dataset', 'detail', 'data_keys'];

function ai_secret_read(string $file): string {
    // Lewat pembaca rahasia terpusat: otomatis mendekripsi amplop AES-256-GCM.
    return adm_secret_read_file($file);
}

function ai_secret_write(string $file, string $value): bool {
    // Lewat penulis rahasia terpusat: terenkripsi bila kunci sudah diaktifkan.
    return adm_secret_write_file($file, $value);
}

function ai_issue_repo(): string {
    $f = ai_secret_read(AI_ISSUE_REPO_F);
    return $f !== '' ? $f : AI_ISSUE_REPO;
}

function ai_http(string $url, array $headers = [], string $body = '', int $timeout = 30): array {
    $ch = curl_init();
    $h  = array_merge(['User-Agent: amalanyaumiyah-admin/1.0', 'Accept: application/json'], $headers);
    if ($body !== '') {
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST  => 'POST',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => array_merge($h, ['Content-Type: application/json']),
            CURLOPT_FOLLOWLOCATION => true,
        ]);
    } else {
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CUSTOMREQUEST  => 'GET',
            CURLOPT_HTTPHEADER     => $h,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
    }
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [
        'ok'   => $resp !== false && $code >= 200 && $code < 300,
        'code' => $code,
        'body' => $resp === false ? '' : $resp,
        'error'=> $err,
    ];
}

function ai_fetch_models(): array {
    $cache = AI_MODELS_CACHE;
    $now   = time();
    if (is_file($cache) && ($now - @filemtime($cache)) < AI_CACHE_TTL) {
        $c = json_decode(@file_get_contents($cache), true);
        if (is_array($c) && isset($c['models'])) return ['ok' => true, 'models' => $c['models'], 'cached' => true];
    }
    $r = ai_http(AI_MODELS_URL, ['Accept: application/json']);
    if (!$r['ok']) {
        $body = $r['body'] !== '' ? ' — ' . mb_substr(preg_replace('/\s+/', ' ', $r['body']), 0, 160) : '';
        return ['ok' => false, 'error' => 'Gagal ambil daftar model OpenRouter (HTTP ' . $r['code'] . ')' . $body . ' ' . $r['error']];
    }
    $data = json_decode($r['body'], true);
    if (!is_array($data) || !isset($data['data'])) {
        return ['ok' => false, 'error' => 'Format daftar model OpenRouter tidak dikenali.'];
    }
    $models = [];
    foreach ($data['data'] as $m) {
        if (!is_array($m)) continue;
        $pricing = $m['pricing'] ?? [];
        $models[] = [
            'id'           => $m['id'] ?? '',
            'ctx'          => (int) ($m['context_length'] ?? 0),
            'prompt'       => (string) ($pricing['prompt'] ?? ''),
            'completion'   => (string) ($pricing['completion'] ?? ''),
        ];
    }
    @file_put_contents($cache, json_encode(['fetched' => $now, 'models' => $models], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    return ['ok' => true, 'models' => $models, 'cached' => false];
}

function ai_free_models(array $models): array {
    $free = array_values(array_filter($models, fn($m) => $m['prompt'] === '0' && $m['completion'] === '0'));
    uasort($free, fn($a, $b) => ($b['ctx'] <=> $a['ctx']) ?: ((str_ends_with($b['id'], ':free') <=> str_ends_with($a['id'], ':free'))));
    return $free;
}

function ai_extract_json(string $text): ?array {
    $text = trim($text);
    $start = strpos($text, '{');
    if ($start === false) return null;
    $depth = 0; $inStr = false; $esc = false;
    for ($i = $start; $i < strlen($text); $i++) {
        $ch = $text[$i];
        if ($inStr) {
            if ($esc) { $esc = false; }
            elseif ($ch === '\\') { $esc = true; }
            elseif ($ch === '"') { $inStr = false; }
            continue;
        }
        if ($ch === '"') { $inStr = true; continue; }
        if ($ch === '{') { $depth++; }
        elseif ($ch === '}') {
            $depth--;
            if ($depth === 0) return json_decode(substr($text, $start, $i - $start + 1), true);
        }
    }
    return null;
}

function ai_validate_targets(array $settings): array {
    $errors   = [];
    $warnings = [];
    $rules    = $settings['rules'] ?? null;
    if (!is_array($rules) || $rules === []) {
        $errors[] = 'rules harus berisi minimal satu aturan.';
        return ['ok' => false, 'errors' => $errors, 'warnings' => $warnings];
    }
    foreach (array_keys($settings) as $k) {
        if (!in_array($k, AI_TOP_KEYS, true)) $warnings[] = "Kunci tidak dikenal di level atas: $k.";
    }
    foreach ($rules as $i => $r) {
        $n = $i + 1;
        $lbl = $r['label'] ?? ('#' . $n);
        if (!is_array($r)) { $errors[] = "Aturan #$n bukan objek."; continue; }
        if (empty($r['label'])) $errors[] = "Aturan #$n: label wajib diisi.";
        if (empty($r['category'])) $warnings[] = "Aturan #$n ($lbl): category kosong.";
        if (!in_array($r['mode'] ?? '', AI_MODES, true)) $errors[] = "Aturan #$n ($lbl): mode tidak dikenal.";
        if (!in_array($r['period'] ?? '', AI_PERIODS, true)) $errors[] = "Aturan #$n ($lbl): periode tidak dikenal.";
        if (!is_numeric($r['target_count'] ?? null)) $errors[] = "Aturan #$n ($lbl): target_count harus angka.";
        $mode = $r['mode'] ?? '';
        if ($mode === 'value_count' && empty($r['match_value'])) $errors[] = "Aturan #$n ($lbl): match_value wajib untuk mode value_count.";
        if ($mode !== 'rawatib' && empty($r['data_keys'])) {
            $errors[] = "Aturan #$n ($lbl): data_keys wajib untuk mode $mode.";
        }
        // Validasi data_keys: semua kunci harus dikenal oleh sistem input mentee
        // (tidak ada input "key lain" di halaman penginputan, jadi kunci tak dikenal
        //  akan selalu menghasilkan capaian 0 — anomali tersembunyi).
        $validDataKeys = ['subuh','dzuhur','ashar','maghrib','isya','rawatib','dhuha','tahajud','witir','senin_kamis','ayamul_bidh','tilawah','sedekah','almatsurat_pagi','almatsurat_petang','istighfar'];
        if (is_array($r['data_keys'] ?? null)) {
            foreach ($r['data_keys'] as $dk) {
                if (!in_array($dk, $validDataKeys, true)) {
                    $warnings[] = "Aturan #$n ($lbl): data_keys '$dk' tidak dikenal sistem input mentee — capaian akan selalu 0.";
                }
            }
        }
        if ($mode === 'numeric' && !is_numeric($r['threshold'] ?? null) && !is_numeric($r['match_value'] ?? null)) {
            $errors[] = "Aturan #$n ($lbl): threshold (atau match_value) harus angka untuk mode numeric.";
        }
        // Kombinasi mode/kunci/periode/match_value yang mustahil tercapai.
        foreach (adm_target_rule_problems($r) as $p) {
            $errors[] = "Aturan #$n ($lbl): $p";
        }
        foreach (array_keys($r) as $k) {
            if (!in_array($k, AI_RULE_KEYS, true)) $warnings[] = "Aturan #$n ($lbl): kunci tidak dikenal '$k'.";
        }
    }
    return ['ok' => $errors === [], 'errors' => $errors, 'warnings' => $warnings];
}

function ai_system_prompt(): string {
    return 'Anda adalah asisten yang menyusun aturan target untuk berkas target_settings.json.'
        . ' Balas HANYA dengan satu objek JSON, tanpa teks tambahan di luar JSON.'
        . ' Struktur yang diharapkan: {"coverable":true,"settings":{"group":"...","title":"...","greeting":"...","intro":"...","closing":"...","tag":"...","rules":[{"category":"SHOLAT WAJIB","label":"Sholat di masjid","target":3,"period":"day","target_count":3,"mode":"value_count","match_value":"M","data_keys":["subuh","dzuhur","ashar","maghrib","isya"]}]}}'
        . ' Jika deskripsi rules tidak dapat diwakili oleh struktur di atas, balas: {"coverable":false,"feedback":"alasan singkat mengapa rules belum dapat diwakili struktur target_settings.json"}.'
        . ' Kategori: SHOLAT WAJIB, SHOLAT SUNNAH, PUASA SUNNAH, TILAWAH, SEDEKAH, ALMATSURAT, ISTIGHFAR.'
        . ' period: day = harian, week = pekanan, month = bulanan. target_count = jumlah target per periode.'
        . ' ATURAN PERIODE: target berulang seperti "3 kali per pekan" WAJIB memakai period="week" target_count=3,'
        . ' dan "4 kali per bulan" memakai period="month" target_count=4. Untuk day_presence, target_count pada'
        . ' period="day" HARUS 1 (maksimal sekali sehari); jangan menulis target lebih dari 1 untuk period day.'
        . ' PEMETAAN KUNCI -> MODE (wajib):'
        . ' subuh/dzuhur/ashar/maghrib/isya (sholat wajib) -> mode "value_count" dengan match_value kode kehadiran'
        . ' ("M"=Masjid/Jamaah, "R-J"=Rumah/Jamaah, "M-S"=Masjid/Sendiri, "R"=Rumah/Sendiri, "Q"=Qadha). Boleh beberapa nilai dipisah koma tanpa spasi, mis. "M,R-J" untuk berjamaah.'
        . ' dhuha/tahajud/witir/senin_kamis/ayamul_bidh/sedekah/almatsurat_pagi/almatsurat_petang (kunci centang, nilainya "✓") -> mode "day_presence" (tanpa match_value).'
        . ' rawatib -> mode "rawatib_count" dengan rakaat_per_check (mis. 2) dan target_count = jumlah rakaat.'
        . ' tilawah -> mode "quran_sheet_count" dengan pages_per_sheet (mis. 2) dan target_count = jumlah lembar per periode.'
        . ' almatsurat_pagi + almatsurat_petang -> mode "filled_key_count" target_count 2 untuk pagi & petang.'
        . ' istighfar -> SATU-SATUNYA kunci angka; gunakan mode "numeric" dengan threshold (mis. 100).'
        . ' JANGAN gunakan mode numeric untuk kunci selain istighfar, dan JANGAN gunakan value_count untuk kunci centang kecuali match_value="✓".'
        . ' data_keys wajib diisi untuk semua mode KECUALI rawatib. Isi dengan kunci yang valid, contoh:'
        . ' ["subuh","dzuhur","ashar","maghrib","isya"] untuk sholat, ["senin_kamis","ayamul_bidh"] untuk puasa, ["tilawah"] untuk tilawah.'
        . ' Jika mode rawatib, data_keys boleh dikosongkan.'
        . ' JANGAN pernah memakai "1"/"0" sebagai match_value.';
}

function ai_chat(string $key, string $model, string $system, string $user, bool $json = true): array {
    $payload = [
        'model'     => $model,
        'messages'  => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $user],
        ],
        'temperature' => 0.2,
    ];
    if ($json) $payload['response_format'] = ['type' => 'json_object'];
    $r = ai_http(AI_API, [
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json',
        'HTTP-Referer: https://amalanyaumiyah.xo.je/',
        'X-Title: amalanyaumiyah-admin',
    ], json_encode($payload, JSON_UNESCAPED_UNICODE), 60);
    if (!$r['ok']) {
        $msg = 'HTTP ' . $r['code'];
        if ($r['body'] !== '') $msg .= ': ' . mb_substr(preg_replace('/\s+/', ' ', $r['body']), 0, 200);
        return ['ok' => false, 'error' => $msg, 'code' => $r['code']];
    }
    $d = json_decode($r['body'], true);
    if (!is_array($d) || !isset($d['choices'][0]['message']['content'])) {
        return ['ok' => false, 'error' => 'Respons model tidak mengandung konten.', 'body' => $r['body']];
    }
    return ['ok' => true, 'text' => $d['choices'][0]['message']['content']];
}

/** Benar bila error adalah jenis yang tidak akan berubah bila dicoba ulang model yang sama. */
function ai_is_skip_error(string $error): bool {
    $e = strtolower($error ?? '');
    return str_contains($e, 'agentic harness')
        || str_contains($e, 'key limit exceeded')
        || str_contains($e, 'daily limit')
        || str_contains($e, 'rate limit')
        || str_contains($e, 'quota')
        || str_contains($e, 'insufficient');
}

function ai_try_models(array $candidates, string $key, string $system, string $user): array {
    $tried = [];
    $max   = min(8, count($candidates));   // coba maksimal 8 model gratis
    for ($i = 0; $i < $max; $i++) {
        $m = $candidates[$i];
        $id = $m['id'];

        // Coba dengan response_format: json_object dulu.
        $r = ai_chat($key, $id, $system, $user, true);
        $tried[] = $id . ' => ' . ($r['ok'] ? 'OK' : $r['error']);
        if ($r['ok']) return ['ok' => true, 'text' => $r['text'], 'tried' => $tried, 'model' => $id];

        // Jika error adalah jenis yang tidak akan berubah di model yang sama,
        // lewati segera ke model berikutnya.
        if (ai_is_skip_error($r['error'] ?? '')) {
            continue;
        }

        // Untuk error response_format / json_object, coba ulang tanpa json_object.
        if (stripos($r['error'] ?? '', 'response_format') !== false
            || stripos($r['error'] ?? '', 'json_object') !== false) {
            $r2 = ai_chat($key, $id, $system, $user, false);
            $tried[] = $id . '(tanpa json_object) => ' . ($r2['ok'] ? 'OK' : $r2['error']);
            if ($r2['ok']) return ['ok' => true, 'text' => $r2['text'], 'tried' => $tried, 'model' => $id];
            if (ai_is_skip_error($r2['error'] ?? '')) {
                continue;
            }
        }

        // Jeda singkat sebelum model berikutnya untuk mengurangi risiko rate limit.
        usleep(400000);
    }
    return ['ok' => false, 'error' => 'Semua model gratis yang dicoba gagal.', 'tried' => $tried];
}

function ai_report_issue(string $title, string $body): array {
    $token = ai_secret_read(AI_GH_TOKEN_F);
    $repo  = ai_issue_repo();
    if ($token === '') {
        return ['ok' => false, 'error' => 'Belum ada token GitHub (test/_ai/github_token). Isi dulu di kolom "Simpan koneksi".'];
    }
    $url = 'https://api.github.com/repos/' . $repo . '/issues';
    $r = ai_http($url, [
        'Authorization: Bearer ' . $token,
        'Accept: application/vnd.github+json',
        'User-Agent: amalanyaumiyah-admin',
        'X-GitHub-Api-Version: 2022-11-28',
    ], json_encode(['title' => $title, 'body' => $body], JSON_UNESCAPED_UNICODE), 30);
    if (!$r['ok']) {
        $msg = 'Gagal membuat issue (HTTP ' . $r['code'] . ').';
        if ($r['code'] === 401 || $r['code'] === 403) {
            $msg .= ' Periksa izin token GitHub (perlu "Issues: Write").';
        } elseif ($r['code'] === 404) {
            $msg .= ' Repository "' . $repo . '" tidak ditemukan atau token tidak punya akses.';
        }
        return ['ok' => false, 'error' => $msg, 'body' => $r['body']];
    }
    $d = json_decode($r['body'], true);
    return ['ok' => true, 'number' => (int) ($d['number'] ?? 0), 'url' => (string) ($d['html_url'] ?? '')];
}

function ai_generate(string $desc, bool $isCross): array {
    $key = ai_secret_read(AI_KEY_FILE);
    if ($key === '') return ['ok' => false, 'error' => 'Kunci OpenRouter belum diisi. Isi di kolom "Simpan koneksi" (test/_ai/openrouter_key).'];
    $m = ai_fetch_models();
    if (!$m['ok']) return ['ok' => false, 'error' => $m['error']];
    $free = ai_free_models($m['models']);
    if ($free === []) return ['ok' => false, 'error' => 'Tidak ada model OpenRouter gratis yang tersedia saat ini.'];
    $system = ai_system_prompt();
    $user = 'Deskripsi rules: "' . $desc . '"'
        . "\nHasilkan berkas settings target_settings.json yang cocok."
        . ' Jika rules tidak dapat diwakili oleh struktur, set coverable false dengan feedback.';
    $res = ai_try_models($free, $key, $system, $user);
    if (!$res['ok']) return ['ok' => false, 'error' => $res['error'], 'tried' => $res['tried']];
    $j = ai_extract_json($res['text']);
    if (!is_array($j)) {
        return ['ok' => false, 'error' => 'Model tidak mengembalikan JSON yang valid.', 'tried' => $res['tried'], 'model' => $res['model'] ?? ''];
    }
    if (isset($j['coverable']) && $j['coverable'] === false) {
        $feedback = (string) ($j['feedback'] ?? 'Rules tidak dapat diwakili oleh struktur target_settings.json saat ini.');
        $gh = ai_secret_read(AI_GH_TOKEN_F);
        $issue = $gh !== '' ? ai_report_issue(
            'Rules target_settings.json tidak tercakup struktur (OpenRouter)',
            'Deskripsi rules: ' . $desc . "\n\nFeedback model: " . $feedback . "\n\nModel yang dicoba: " . implode(', ', $res['tried']) . '.'
        ) : ['ok' => false, 'error' => 'Belum ada token GitHub.'];
        return ['ok' => false, 'coverable' => false, 'feedback' => $feedback, 'issue' => $issue, 'tried' => $res['tried'], 'model' => $res['model'] ?? ''];
    }
    $settings = $j['settings'] ?? null;
    if (!is_array($settings)) {
        return ['ok' => false, 'error' => 'JSON tidak memiliki kunci "settings".', 'tried' => $res['tried'], 'model' => $res['model'] ?? ''];
    }

    // Normalisasi hasil AI agar valid (menghindari aturan yang selalu 0%).
    if (is_array($settings['rules'] ?? null)) {
        $groups       = adm_key_value_groups();
        $checkboxKeys = $groups['checkbox'];
        $numericKeys  = $groups['numeric'];

        foreach ($settings['rules'] as &$rule) {
            if (!is_array($rule)) {
                continue;
            }

            $mode = strtolower((string) ($rule['mode'] ?? ''));
            $dk   = is_array($rule['data_keys'] ?? null)
                ? array_values(array_filter(array_map(static fn($k) => is_string($k) ? trim($k) : '', $rule['data_keys']), static fn($k) => $k !== ''))
                : [];

            // Model menaruh nilai angka di match_value untuk mode numeric.
            if ($mode === 'numeric'
                && !is_numeric($rule['threshold'] ?? null)
                && is_numeric($rule['match_value'] ?? null)) {
                $rule['threshold'] = $rule['match_value'];
            }

            // numeric hanya untuk kunci angka (istighfar). Bila dipakai untuk
            // kunci centang/presence, ubah ke day_presence.
            if ($mode === 'numeric' && $dk !== [] && array_diff($dk, $numericKeys) !== []) {
                $rule['mode'] = 'day_presence';
                unset($rule['threshold']);
                $mode = 'day_presence';
            }

            // value_count pada kunci centang dengan match_value "1"/"0"/dsb.
            if ($mode === 'value_count' && $dk !== [] && array_diff($dk, $checkboxKeys) === []) {
                $mv = is_scalar($rule['match_value'] ?? null) ? trim((string) $rule['match_value']) : '';
                if (in_array($mv, ['1', '0', 'yes', 'true', 'v', 'checked', 'centang', '✓'], true)) {
                    $rule['match_value'] = '✓';
                }
            }

            // day_presence periode harian maksimal 1 kejadian, jadi target_count
            // yang lebih besar tidak mungkin tercapai. Kecilkan ke 1.
            if ($mode === 'day_presence'
                && strtolower((string) ($rule['period'] ?? 'day')) === 'day'
                && is_numeric($rule['target_count'] ?? null)
                && (float) $rule['target_count'] > 1) {
                $rule['target_count'] = 1;
            }
        }
        unset($rule);
    }

    $val = ai_validate_targets($settings);
    if (!$val['ok']) {
        $feedback = 'Hasil AI tidak lolos validasi struktur: ' . implode('; ', $val['errors']);
        $gh = ai_secret_read(AI_GH_TOKEN_F);
        $issue = $gh !== '' ? ai_report_issue(
            'Hasil AI target_settings.json gagal validasi struktur (OpenRouter)',
            'Deskripsi rules: ' . $desc . "\n\nValidasi error: " . $feedback . "\n\nHasil model: " . json_encode($settings, JSON_UNESCAPED_UNICODE) . '.'
        ) : ['ok' => false, 'error' => 'Belum ada token GitHub.'];
        return ['ok' => false, 'coverable' => false, 'feedback' => $feedback, 'issue' => $issue, 'tried' => $res['tried'], 'model' => $res['model'] ?? ''];
    }
    if ($isCross) $settings['group'] = 'LINTAS GRUP';
    $settings['rules'] = array_values(array_filter($settings['rules'], fn($r) => is_array($r) && !empty($r['label'])));
    return ['ok' => true, 'settings' => $settings, 'model' => $res['model'] ?? '', 'feedback' => isset($j['feedback']) ? (string) $j['feedback'] : '', 'tried' => $res['tried']];
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

// ======================= KEAMANAN RAHASIA (TOKEN & API KEY) =======================
// Token GitHub & kunci OpenRouter disimpan sebagai berkas di server (mode 600).
// Bila masih apa adanya (plaintext), siapa pun yang bisa membaca berkas server
// dapat melihatnya. Halaman ini bisa mengenkripsinya sekali klik:
//   - Kunci enkripsi (32 byte) dibungkus dengan bahan internal (adm_cred_secret())
//     lalu DITAMBAL ke test/admin.json (bidang secret_key/secret_key_iv/secret_key_tag).
//   - Tiap berkas rahasia ditulis ulang sebagai amplop AES-256-GCM.
// Berkas rahasia tidak ikut ke git maupun FTP, jadi kuncinya hanya ada di server.

/** Isi mentah test/admin.json (belum didekripsi). Null bila tidak ada/rusak. */
function adm_cred_raw(): ?array {
    if (!is_file(ADMIN_CRED_FILE)) return null;
    $j = json_decode((string) @file_get_contents(ADMIN_CRED_FILE), true);
    return is_array($j) ? $j : null;
}

/** Tambal (patch) sebagian bidang test/admin.json; bidang lain dipertahankan. */
function adm_cred_patch(array $fields): bool {
    $j = adm_cred_raw();
    if ($j === null) return false;
    foreach ($fields as $k => $v) $j[$k] = $v;
    $json = json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    if (@file_put_contents(ADMIN_CRED_FILE, $json . "\n", LOCK_EX) === false) return false;
    @chmod(ADMIN_CRED_FILE, 0600);
    return true;
}

/** Kunci enkripsi rahasia (32 byte) dari admin.json, atau null bila belum ada. */
function adm_secret_key(): ?string {
    $j = adm_cred_raw();
    if ($j === null || empty($j['secret_key'])) return null;
    $kw  = base64_decode((string) $j['secret_key'], true);
    $kiv = base64_decode((string) ($j['secret_key_iv'] ?? ''), true);
    $ktg = base64_decode((string) ($j['secret_key_tag'] ?? ''), true);
    if ($kw === false || $kw === '' || $kiv === false || $ktg === false) return null;
    $key = openssl_decrypt($kw, 'aes-256-gcm', adm_cred_secret(), OPENSSL_RAW_DATA, $kiv, $ktg);
    return $key === false ? null : $key;
}

/** Catatan singkat status enkripsi untuk umpan balik di UI. */
function adm_secret_note(): string {
    return adm_secret_key() !== null
        ? 'terenkripsi'
        : 'PLAINTEXT (buka panel "Keamanan rahasia" untuk mengenkripsi)';
}

/** Daftar berkas rahasia yang dipantau beserta labelnya. */
function adm_secret_targets(): array {
    return [
        ['label' => 'Token GitHub — manifest PWA', 'path' => GITHUB_TOKEN_FILE],
        ['label' => 'Kunci OpenRouter (AI)',        'path' => AI_KEY_FILE],
        ['label' => 'Token GitHub — issue AI',      'path' => AI_GH_TOKEN_F],
    ];
}

/** Benar bila isi berkas berbentuk amplop enkripsi yang dikenali. */
function adm_secret_is_envelope(string $raw): bool {
    $raw = ltrim($raw);
    if ($raw === '' || $raw[0] !== '{') return false;
    $j = json_decode($raw, true);
    return is_array($j) && ($j['alg'] ?? '') === 'aes-256-gcm'
        && isset($j['iv'], $j['tag'], $j['data']);
}

/** Enkripsi nilai dengan kunci rahasia -> string amplop JSON. Null bila gagal. */
function adm_secret_encrypt(string $plain): ?string {
    $key = adm_secret_key();
    if ($key === null) return null;
    $iv = random_bytes(12); $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($ct === false) return null;
    return json_encode([
        'alg'  => 'aes-256-gcm',
        'v'    => 1,
        'iv'   => base64_encode($iv),
        'tag'  => base64_encode($tag),
        'data' => base64_encode($ct),
    ], JSON_UNESCAPED_SLASHES);
}

/** Dekripsi amplop -> nilai asli, atau null bila kunci tidak ada / gagal. */
function adm_secret_decrypt(string $raw): ?string {
    $j = json_decode($raw, true);
    if (!is_array($j)) return null;
    $key = adm_secret_key();
    if ($key === null) return null;
    $iv  = base64_decode((string) ($j['iv']   ?? ''), true);
    $tag = base64_decode((string) ($j['tag']  ?? ''), true);
    $ct  = base64_decode((string) ($j['data'] ?? ''), true);
    if ($iv === false || $tag === false || $ct === false) return null;
    $p = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return $p === false ? null : $p;
}

/**
 * Baca rahasia dari berkas: otomatis mendekripsi bila berbentuk amplop, atau
 * mengembalikan apa adanya bila masih plaintext. String kosong bila tak ada.
 */
function adm_secret_read_file(string $file): string {
    if (!is_file($file)) return '';
    $raw = (string) @file_get_contents($file);
    if (trim($raw) === '') return '';
    if (adm_secret_is_envelope($raw)) {
        $p = adm_secret_decrypt($raw);
        return $p === null ? '' : trim($p);
    }
    return trim($raw);
}

/** Tulis rahasia: dienkripsi bila kunci sudah ada, jika tidak ditulis plaintext. */
function adm_secret_write_file(string $file, string $value): bool {
    $dir = dirname($file);
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $payload = $value;
    if ($value !== '' && adm_secret_key() !== null) {
        $enc = adm_secret_encrypt($value);
        if ($enc === null) return false;
        $payload = $enc;
    }
    $ok = @file_put_contents($file, $payload . ($payload !== '' ? "\n" : ''), LOCK_EX) !== false;
    if ($ok) @chmod($file, 0600);
    return $ok;
}

/** Status berkas rahasia: missing | plain | encrypted | locked. */
function adm_secret_status(string $file): string {
    if (!is_file($file)) return 'missing';
    $raw = (string) @file_get_contents($file);
    if (trim($raw) === '') return 'missing';
    if (!adm_secret_is_envelope($raw)) return 'plain';
    return adm_secret_decrypt($raw) === null ? 'locked' : 'encrypted';
}

/** Ada rahasia yang masih plaintext / terkunci? Dipakai untuk membuka panel. */
function adm_secret_needs_attention(): bool {
    foreach (adm_secret_targets() as $t) {
        $s = adm_secret_status($t['path']);
        if ($s === 'plain' || $s === 'locked') return true;
    }
    return false;
}

/** Jumlah berkas rahasia per status. */
function adm_secret_counts(): array {
    $c = ['missing' => 0, 'plain' => 0, 'encrypted' => 0, 'locked' => 0];
    foreach (adm_secret_targets() as $t) {
        $c[adm_secret_status($t['path'])]++;
    }
    return $c;
}

/** Badge ringkas status rahasia: ['text'=>..., 'class'=>...]. */
function adm_secret_badge(): array {
    $c = adm_secret_counts();
    if ($c['plain'] > 0)     return ['text' => $c['plain'] . ' plaintext', 'class' => 'trash'];
    if ($c['locked'] > 0)    return ['text' => $c['locked'] . ' terkunci', 'class' => 'mentor'];
    if ($c['encrypted'] > 0) return ['text' => 'terenkripsi', 'class' => 'mentee'];
    return ['text' => 'belum diisi', 'class' => 'muted'];
}

/**
 * Aktifkan enkripsi: buat kunci (bila belum ada) + tambal ke admin.json, lalu
 * enkripsi semua berkas rahasia yang masih plaintext.
 * @return array{ok:bool,messages:string[]}
 */
function adm_secret_enable_encryption(): array {
    $msgs = [];

    // 1) Kunci: buat & tambal ke test/admin.json bila belum ada.
    if (adm_secret_key() === null) {
        $key = random_bytes(32);
        $kiv = random_bytes(12); $ktg = '';
        $kw  = openssl_encrypt($key, 'aes-256-gcm', adm_cred_secret(), OPENSSL_RAW_DATA, $kiv, $ktg);
        if ($kw === false) {
            return ['ok' => false, 'messages' => ['Gagal membuat kunci enkripsi.']];
        }
        if (!adm_cred_patch([
            'secret_key'     => base64_encode($kw),
            'secret_key_iv'  => base64_encode($kiv),
            'secret_key_tag' => base64_encode($ktg),
        ])) {
            return ['ok' => false, 'messages' => ['Gagal menulis kunci ke test/admin.json.']];
        }
        // admin.json berubah -> perbarui sidik jari sesi agar tidak ter-logout.
        $_SESSION[ADMIN_SESSION_CRED] = (string) adm_cred_fingerprint();
        $msgs[] = 'Kunci enkripsi dibuat & ditambal ke test/admin.json.';
    } else {
        $msgs[] = 'Kunci enkripsi sudah ada di test/admin.json.';
    }

    // 2) Enkripsi berkas yang masih plaintext.
    $done = 0; $fail = 0;
    foreach (adm_secret_targets() as $t) {
        $st = adm_secret_status($t['path']);
        if ($st === 'missing' || $st === 'encrypted') continue;
        if ($st === 'locked') {
            $msgs[] = $t['label'] . ': terenkripsi tetapi kunci TIDAK cocok — isi ulang nilai ini manual.';
            $fail++;
            continue;
        }
        $val = trim((string) @file_get_contents($t['path']));
        $enc = adm_secret_encrypt($val);
        if ($enc === null || @file_put_contents($t['path'], $enc . "\n", LOCK_EX) === false) {
            $msgs[] = $t['label'] . ': GAGAL dienkripsi.';
            $fail++;
        } else {
            @chmod($t['path'], 0600);
            $msgs[] = $t['label'] . ': dienkripsi.';
            $done++;
        }
    }

    if ($done === 0 && $fail === 0) {
        $msgs[] = 'Tidak ada berkas plaintext — semua sudah terenkripsi atau belum diisi.';
    }

    return ['ok' => $fail === 0, 'messages' => $msgs];
}

/** Tabel status rahasia (HTML) untuk panel admin. */
function adm_render_secret_status(): string {
    $keyOk = adm_secret_key() !== null;
    $badge = [
        'encrypted' => ['Terenkripsi',           'mentee'],
        'plain'     => ['PLAINTEXT',             'trash'],
        'missing'   => ['Belum diisi',           'muted'],
        'locked'    => ['Terkunci (kunci beda)', 'mentor'],
    ];
    $html = '<table><thead><tr><th>Rahasia</th><th>Berkas</th><th>Status</th></tr></thead><tbody>';
    foreach (adm_secret_targets() as $t) {
        $st  = adm_secret_status($t['path']);
        $b   = $badge[$st] ?? ['?', 'muted'];
        $rel = str_replace(__DIR__ . '/', '', $t['path']);
        $html .= '<tr><td>' . $t['label'] . '</td>'
               . '<td><code>' . adm_h($rel) . '</code></td>'
               . '<td><span class="tag ' . $b[1] . '">' . adm_h($b[0]) . '</span></td></tr>';
    }
    $html .= '</tbody></table>';
    $html .= '<p class="hint">Kunci enkripsi: '
           . ($keyOk ? '<strong>tersedia</strong> di <code>test/admin.json</code>' : '<strong>belum dibuat</strong>')
           . '. Berkas rahasia tidak ikut ke git maupun FTP.</p>';
    return $html;
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
                    $val = adm_validate_target_structure($data, $group);
                    if (!$val['ok']) {
                        foreach ($val['errors'] as $e) {
                            $reply['messages'][] = adm_msg($e, 'err');
                        }
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
        }

        // ---- Target khusus mode "semua kelompok" (lintas grup) ----
        // Ditulis ke test/bi/target_settings.json. Berdiri sendiri:
        // tidak mengubah target_settings.json grup mana pun.
        if ($action === 'save_targets_all') {
            $raw  = (string) ($_POST['settings'] ?? '');
            $data = json_decode($raw, true);

            if (!is_array($data)) {
                $reply['messages'][] = adm_msg('Data target lintas grup tidak valid (bukan JSON).', 'err');
            } else {
                $val = adm_validate_target_structure($data, 'LINTAS GRUP');
                if (!$val['ok']) {
                    foreach ($val['errors'] as $e) {
                        $reply['messages'][] = adm_msg($e, 'err');
                    }
                } else {
                    $clean = adm_normalize_targets($data, 'LINTAS GRUP');
                    $res   = adm_write_targets(adm_cross_target_file(), $clean);
                    if ($res['ok']) {
                        $reply['ok'] = true;
                        $reply['messages'][] = adm_msg(
                            'Target khusus lintas grup disimpan (' . count($clean['rules']) . ' aturan).'
                        );
                    } else {
                        $reply['messages'][] = adm_msg($res['error'], 'err');
                    }
                }
            }
        }

        // ---- Hapus target settings grup (target_settings.json) ----
        if ($action === 'delete_targets') {
            $group = trim((string) ($_POST['group'] ?? ''));

            if (!adm_valid_name($group) || !is_dir($root . '/' . $group)) {
                $reply['messages'][] = adm_msg('Grup tidak ditemukan.', 'err');
            } else {
                $file = adm_target_file($root, $group);
                if (!is_file($file)) {
                    $reply['messages'][] = adm_msg("Target grup '$group' belum ada.", 'err');
                } elseif (@unlink($file)) {
                    $reply['ok'] = true;
                    $reply['messages'][] = adm_msg("Target grup '$group' dihapus.");
                } else {
                    $reply['messages'][] = adm_msg("Gagal menghapus target grup '$group'.", 'err');
                }
            }
        }

        // ---- Hapus target khusus lintas grup (test/bi/target_settings.json) ----
        if ($action === 'delete_targets_all') {
            $file = adm_cross_target_file();
            if (!is_file($file)) {
                $reply['messages'][] = adm_msg('Target lintas grup belum ada.', 'err');
            } elseif (@unlink($file)) {
                $reply['ok'] = true;
                $reply['messages'][] = adm_msg('Target khusus lintas grup dihapus.');
            } else {
                $reply['messages'][] = adm_msg('Gagal menghapus target lintas grup.', 'err');
            }
        }

        // ---- AI OpenRouter: koneksi, uji, dan penyusun rules ----
        if ($action === 'or_save_conn') {
            $orKey = trim((string) ($_POST['or_key'] ?? ''));
            $ghTok = trim((string) ($_POST['gh_token'] ?? ''));
            $repo  = trim((string) ($_POST['issue_repo'] ?? ''));
            $saved = [];

            if ($orKey !== '') {
                $saved[] = ai_secret_write(AI_KEY_FILE, $orKey)
                    ? 'Kunci OpenRouter disimpan (test/_ai/openrouter_key, mode 600, ' . adm_secret_note() . ').'
                    : 'Gagal menulis kunci OpenRouter — periksa hak akses folder test/_ai/.';
            }
            if ($ghTok !== '') {
                if (!adm_gh_token_format_ok($ghTok)) {
                    $reply['messages'][] = adm_msg(
                        'Format token GitHub tidak dikenali (harus github_pat_... atau ghp_...). Token tidak disimpan.', 'err');
                } else {
                    $saved[] = ai_secret_write(AI_GH_TOKEN_F, $ghTok)
                        ? 'Token GitHub untuk issue disimpan (test/_ai/github_token, ' . adm_secret_note() . ').'
                        : 'Gagal menulis token GitHub — periksa hak akses folder test/_ai/.';
                }
            }
            if ($repo !== '') {
                if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
                    $saved[] = ai_secret_write(AI_ISSUE_REPO_F, $repo)
                        ? "Repository tujuan issue: $repo."
                        : 'Gagal menulis nama repository issue.';
                } else {
                    $reply['messages'][] = adm_msg("Repository \"$repo\" tidak valid; formatnya owner/repo.", 'err');
                }
            }

            if ($saved === []) {
                $reply['messages'][] = adm_msg('Tidak ada kolom yang diisi — data tersimpan dibiarkan seperti adanya.', 'err');
            } else {
                $reply['ok'] = true;
                foreach ($saved as $s) $reply['messages'][] = adm_msg($s);
            }
        }

        if ($action === 'or_clear_conn') {
            ai_secret_write(AI_KEY_FILE, '');
            ai_secret_write(AI_GH_TOKEN_F, '');
            $reply['ok'] = true;
            $reply['messages'][] = adm_msg('Kunci OpenRouter & token GitHub dihapus.');
        }

        // ---- Keamanan rahasia: status & enkripsi token/API key ----
        if ($action === 'sec_status') {
            $reply['ok'] = true;
            $reply['html'] = adm_render_secret_status();
            $reply['sec_badge'] = adm_secret_badge();
            $c = adm_secret_counts();
            if ($c['plain'] > 0) {
                $reply['messages'][] = adm_msg(
                    $c['plain'] . ' rahasia masih PLAINTEXT. Klik "Enkripsi semua" untuk mengamankannya.',
                    'err');
            } elseif ($c['locked'] > 0) {
                $reply['messages'][] = adm_msg(
                    $c['locked'] . ' rahasia terkunci (kunci enkripsi tidak cocok). Isi ulang nilai rahasia itu.',
                    'err');
            } elseif ($c['encrypted'] > 0) {
                $reply['messages'][] = adm_msg('Semua rahasia tersimpan terenkripsi (AES-256-GCM).');
            } else {
                $reply['messages'][] = adm_msg('Belum ada rahasia yang tersimpan.');
            }
        }

        if ($action === 'sec_encrypt') {
            $r = adm_secret_enable_encryption();
            $reply['ok'] = $r['ok'];
            $reply['html'] = adm_render_secret_status();
            $reply['sec_badge'] = adm_secret_badge();
            foreach ($r['messages'] as $m) $reply['messages'][] = adm_msg($m, $r['ok'] ? 'ok' : 'err');
        }

        if ($action === 'or_test_conn') {
            $key  = ai_secret_read(AI_KEY_FILE);
            $free = [];

            if ($key === '') {
                $reply['messages'][] = adm_msg('Kunci OpenRouter belum diisi.', 'err');
            } else {
                $reply['messages'][] = adm_msg('Kunci OpenRouter terbaca (' . strlen($key) . ' karakter).');
            }

            $gh = ai_secret_read(AI_GH_TOKEN_F);
            $reply['messages'][] = adm_msg(
                $gh !== ''
                    ? 'Token GitHub issue tersimpan, repository ' . ai_issue_repo() . '.'
                    : 'Token GitHub BELUM tersimpan — issue tidak akan dibuat bila rules tidak tercakup struktur.'
                        . (adm_gh_token() !== '' ? ' (Akan dicoba token repo manifest sebagai cadangan.)' : ''),
                $gh !== '' ? 'ok' : 'err'
            );

            $m = ai_fetch_models();
            if (!$m['ok']) {
                $reply['messages'][] = adm_msg($m['error'], 'err');
            } else {
                $free = ai_free_models($m['models']);
                $reply['messages'][] = adm_msg(
                    count($m['models']) . ' model terdaftar, ' . count($free) . ' di antaranya GRATIS dan siap dipakai'
                    . ($m['cached'] ? ' (daftar dari cache).' : '.'),
                    $free === [] ? 'err' : 'ok'
                );
                foreach (array_slice($free, 0, 3) as $c) {
                    $reply['messages'][] = adm_msg('Kandidat otomatis: ' . $c['id'] . ' (konteks ' . number_format($c['ctx']) . ')');
                }
            }

            // Uji nyata: satu panggilan kecil ke model kandidat pertama.
            if ($key !== '' && $free !== []) {
                $id = $free[0]['id'];
                $r  = ai_chat($key, $id, 'Balas hanya JSON: {"ok":true}.', 'uji koneksi', true);
                if ($r['ok']) {
                    $reply['ok'] = true;
                    $reply['messages'][] = adm_msg("Model $id merespons: "
                        . mb_substr(trim((string) preg_replace('/\s+/', ' ', $r['text'])), 0, 80));
                } else {
                    $reply['messages'][] = adm_msg(
                        "Model $id gagal: {$r['error']}. Model lain bisa tetap bisa — saat menyusun, model gratis berikutnya otomatis dicoba.",
                        'err'
                    );
                }
            }
        }

        if ($action === 'or_generate') {
            $desc    = trim((string) ($_POST['desc'] ?? ''));
            $isCross = ($_POST['cross'] ?? '') === '1';

            if (mb_strlen($desc) < 10) {
                $reply['messages'][] = adm_msg('Tulis deskripsi rules dulu (minimal 10 karakter).', 'err');
            } else {
                $g = ai_generate($desc, $isCross);

                if ($g['ok']) {
                    $nRules = is_array($g['settings']['rules'] ?? null) ? count($g['settings']['rules']) : 0;
                    $reply['ok']         = true;
                    $reply['settings']   = $g['settings'];
                    $reply['model']      = (string) ($g['model'] ?? '');
                    $reply['messages'][] = adm_msg('Model ' . $reply['model'] . " menyusun $nRules aturan."
                        . ' Hasil diisi ke editor di bawah — periksa, lalu klik Simpan target.');
                    if (!empty($g['feedback'])) {
                        $reply['messages'][] = adm_msg($g['feedback'], 'err');
                    }
                } elseif (!empty($g['feedback'])) {
                    // Rules tidak tercakup struktur target_settings.json:
                    // umpan balik ke halaman admin + issue ke repository proyek.
                    $reply['feedback']   = $g['feedback'];
                    $reply['messages'][] = adm_msg('Rules BELUM tercakup struktur target_settings.json.', 'err');
                    $reply['messages'][] = adm_msg(str_replace("\n", ' ', mb_substr($g['feedback'], 0, 400)), 'err');

                    $issue = $g['issue'] ?? null;
                    if (is_array($issue)) {
                        if (!empty($issue['ok'])) {
                            $reply['issue'] = $issue;
                            $reply['messages'][] = adm_msg('Issue dibuat di repository proyek: ' . $issue['url']);
                        } else {
                            $reply['messages'][] = adm_msg((string) ($issue['error'] ?? 'Issue tidak dibuat.'), 'err');
                        }
                    }
                } else {
                    $reply['messages'][] = adm_msg((string) ($g['error'] ?? 'Gagal menyusun rules.'), 'err');
                    if (!empty($g['tried'])) {
                        $reply['messages'][] = adm_msg('Model dicoba: ' . implode(' | ', array_slice($g['tried'], 0, 6)), 'err');
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
            $kind = adm_gh_token_kind();
            $reply['messages'][] = adm_msg('Token terdeteksi: ' . ($kind === 'fine' ? 'fine-grained' : ($kind === 'classic' ? 'classic' : 'tidak dikenali')) . '.');
            // /user opsional: sebagian token fine-grained membatasinya.
            $me = adm_gh_api('GET', 'https://api.github.com/user');
            if ($me['ok']) {
                $reply['messages'][] = adm_msg("Tersambung sebagai @{$me['data']['login']}.");
            } else {
                $reply['messages'][] = adm_msg('Info akun tidak tersedia (' . $me['error'] . '). Lanjut cek repo.', 'err');
            }
            // Yang wajib: akses ke repo manifest, plus izin tulis bila diketahui.
            $repo = adm_gh_api('GET', 'https://api.github.com/repos/' . MANIFEST_REPO);
            if ($repo['ok']) {
                $reply['ok'] = true;
                $reply['messages'][] = adm_msg("Repo '{$repo['data']['full_name']}' dapat diakses (branch default: {$repo['data']['default_branch']}).");
                $perm = is_array($repo['data']['permissions'] ?? null) ? $repo['data']['permissions'] : null;
                if ($perm !== null) {
                    $canPush = !empty($perm['push']);
                    $reply['messages'][] = adm_msg(
                        $canPush ? 'Izin tulis (Contents: Read and write): tersedia.'
                                 : 'Izin tulis BELUM terdeteksi — pastikan "Contents: Read and write" pada token.',
                        $canPush ? 'ok' : 'err'
                    );
                }
            } else {
                $reply['ok'] = false;
                $reply['messages'][] = adm_msg('Repo tidak bisa diakses: ' . $repo['error'], 'err');
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
                // Generate dari sumber kebenaran, simpan salinan lokal, lalu tulis ke repo.
                $res = adm_write_manifest($name, $group, is_file($root . '/' . $group . '/' . $name . '/' . MENTOR_FLAG));
                if (!$res['ok']) {
                    $reply['messages'][] = adm_msg($res['error'], 'err');
                } else {
                    $r = adm_gh_put(adm_manifest_filename($name), $res['json'], "manifest: $group/$name");
                    if ($r['ok']) {
                        $reply['ok'] = true;
                        $reply['messages'][] = adm_msg("Manifest '$name' ditulis ke repo " . MANIFEST_REPO . '.');
                        $reply['messages'][] = adm_msg('Raw: ' . adm_manifest_raw_url($name));
                    } else {
                        $reply['messages'][] = adm_msg('Gagal menulis ke repo: ' . $r['error'], 'err');
                    }
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
                    $res = adm_write_manifest($m, $g, is_file($root . '/' . $g . '/' . $m . '/' . MENTOR_FLAG));
                    if (!$res['ok']) { $fail++; $errors[] = "$m: " . $res['error']; continue; }

                    $r = adm_gh_put(adm_manifest_filename($m), $res['json'], "manifest: $g/$m");
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
                    adm_prune_local_manifests(); // buang salinan manifest yang sudah tak dipakai
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
                    adm_prune_local_manifests();
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
                    adm_prune_local_manifests();
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

                // Salinan manifest lokal dibuang saat grup dihapus (lihat trash_group).
                // Buat ulang agar folder test/_manifest/ kembali mencerminkan keadaan kini.
                $newDir = $root . '/' . $group;
                $gm = adm_write_group_manifest($group);
                if ($gm['ok'] && adm_gh_token() !== '') {
                    $pr = adm_gh_put(adm_group_manifest_filename($group), $gm['json'], "manifest: grup $group");
                    $reply['messages'][] = adm_msg(
                        $pr['ok'] ? "Manifest grup '$group' diterbitkan ulang ke repo."
                                  : "Manifest grup lokal dibuat, tapi gagal ke repo: {$pr['error']}",
                        $pr['ok'] ? 'ok' : 'err'
                    );
                } elseif ($gm['ok']) {
                    $reply['messages'][] = adm_msg(
                        "Manifest grup '$group' dibuat lokal. Terbitkan lewat tombol 'Tulis manifest grup'.", 'ok'
                    );
                }

                $mOk = 0; $mErr = 0;
                foreach (adm_subfolders($newDir) as $m) {
                    $msgs = adm_publish_mentee_manifest($root, $group, $m, is_file($newDir . '/' . $m . '/' . MENTOR_FLAG));
                    foreach ($msgs as $mm) (($mm['type'] ?? '') === 'err') ? $mErr++ : $mOk++;
                }
                if ($mOk || $mErr) {
                    $reply['messages'][] = adm_msg(
                        "$mOk langkah manifest mentee dibuat ulang" . ($mErr ? ", $mErr gagal." : '.'),
                        $mErr ? 'err' : 'ok'
                    );
                }
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
.gh-file-list{margin:8px 0 0;padding-left:18px;font-size:.85rem;line-height:1.8}
.gh-file-list a{color:var(--accent)}
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
.tgt-keys{display:flex;flex-wrap:wrap;gap:6px;margin:2px 0 6px}
.tgt-chip{background:#08130f;border:1px solid var(--line2);color:var(--muted);border-radius:999px;
          padding:4px 10px;font:inherit;font-size:.75rem;cursor:pointer;line-height:1.3}
.tgt-chip:hover{border-color:var(--accent);color:var(--text)}
.tgt-chip.on{background:rgba(125,240,197,.18);border-color:var(--accent);color:var(--accent);font-weight:700}
.tgt-keys-box{display:flex;flex-direction:column;gap:2px}
.tgt-keys-box input{background:#08130f;border:1px solid var(--line2);color:var(--text);border-radius:8px;padding:6px 9px;font:inherit}
.tgt-mv-note{color:var(--muted);font-size:.7rem;line-height:1.45}
.tgt-moderow{display:flex;gap:10px;align-items:flex-start;margin:-2px 0 8px;flex-wrap:wrap}
.tgt-modehint{color:var(--muted);font-size:.74rem;line-height:1.5;flex:1;min-width:180px}
.tgt-auto{padding:4px 10px !important;font-size:.72rem !important}
.tgt-preview{margin-top:8px;padding:8px 10px;border:1px dashed var(--line2);border-radius:9px;
             color:var(--muted);font-size:.76rem;line-height:1.5;word-break:break-word}
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
button.ghost.copied{border-color:var(--ok);color:var(--ok)}
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

<!-- Pencarian cepat panel admin: grup, mentee, aturan target, tempat sampah -->
<div class="panel adm-find" id="adm-find">
    <input type="search" id="adm-find-input" placeholder="Cari grup, mentee, aturan target, sampah (tekan / untuk fokus)…" autocomplete="off" role="search">
    <div id="adm-find-out"></div>
    <div id="adm-find-info"></div>
</div>
<style>
/* Panel pencarian cepat admin — cocok tema halaman */
#adm-find{position:sticky;top:0;z-index:40;margin:0 0 14px;padding:12px 14px;
    background:var(--surface);border:1px solid var(--line);border-radius:12px}
#adm-find-input{width:100%;background:#08130f;border:1px solid var(--line2);
    color:var(--text);border-radius:9px;padding:10px 12px;font:inherit;
    box-sizing:border-box}
#adm-find-input:focus{outline:2px solid var(--accent);outline-offset:1px}
input[type=password]{background:#08130f;border:1px solid var(--line2);
    color:var(--text);border-radius:9px;padding:9px 12px;font:inherit;
    box-sizing:border-box}
input[type=password]:focus{outline:2px solid var(--accent);outline-offset:1px}
#adm-find-info{color:var(--muted);font-size:.85rem;padding:8px 12px}
.adm-find-k{display:inline-block;width:52px;font-size:.72rem;font-weight:700;
    border-radius:6px;padding:1px 6px;margin-right:6px;letter-spacing:.04em;
    background:var(--line2);color:var(--muted)}
.adm-find-k.Grup{background:rgba(143,240,176,.15);border:1px solid rgba(143,240,176,.35);
    color:var(--ok)}
.adm-find-k.Mentee{background:rgba(125,240,197,.15);border:1px solid rgba(125,240,197,.35);
    color:var(--accent)}
.adm-find-k.Aturan{background:rgba(255,210,125,.15);border:1px solid rgba(255,210,125,.35);
    color:var(--accent2)}
.adm-find-k.Sampah{background:rgba(255,139,139,.15);border:1px solid rgba(255,139,139,.35);
    color:var(--danger)}
.adm-find-n{font-weight:600}
.adm-find-s{font-size:.78rem;color:var(--muted)}
.adm-find-out{display:flex;flex-direction:column;gap:5px;margin-top:9px}
.adm-find-out ul{margin:0;padding-left:20px;list-style:disc}
.adm-find-out li{font-size:.88rem}
.adm-find-out li button{width:100%;text-align:left;background:none;border:none;
    padding:7px 9px;border-radius:8px;cursor:pointer;font:inherit;color:var(--text)}
.adm-find-out li button:hover{background:var(--surface2);border-color:var(--line2)}
.adm-find-hit{outline:2px solid var(--accent);outline-offset:4px;border-radius:12px}
/* Blok "Susun dengan AI" di dalam editor target — cocok tema halaman */
.tgt-ai{margin-top:14px;padding:12px;border:1px dashed var(--line2);border-radius:10px;background:var(--surface2)}
.tgt-ai-desc{width:100%;background:#08130f;border:1px solid var(--line2);
    color:var(--text);border-radius:8px;padding:9px 11px;resize:vertical;min-height:64px;
    font:inherit;box-sizing:border-box}
.tgt-ai-desc:focus{outline:2px solid var(--accent);outline-offset:1px}
.tgt-ai-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:9px}
.tgt-ai-out{margin-top:9px;white-space:pre-wrap}
.tgt-ai-out:empty{display:none}
.tgt-ai-msg{font-size:.82rem;line-height:1.5;padding:9px 11px;border-radius:8px;margin-top:6px;
    background:var(--surface);border:1px solid var(--line)}
.tgt-ai-msg.ok{border-color:var(--ok);color:var(--ok)}
.tgt-ai-msg.err{border-color:var(--danger);color:var(--danger)}
/* Notifikasi dalam panel OpenRouter (#or-msg) */
.or-msg-item{font-size:.82rem;padding:8px 10px;border-radius:8px;margin-top:6px;
    background:var(--surface);border:1px solid var(--line)}
.or-msg-item.ok{border-color:var(--ok);color:var(--ok)}
.or-msg-item.err{border-color:var(--danger);color:var(--danger)}
</style>

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

<?php
    $crossTargetFile = adm_cross_target_file();
    $crossTargets    = adm_read_targets_file($crossTargetFile);
    $crossRules      = is_array($crossTargets['rules'] ?? null) ? $crossTargets['rules'] : [];
?>
<section class="panel">
    <h2 style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        Target khusus &mdash; Analisis Semua Kelompok
        <span class="tag <?= $crossRules ? 'mentee' : 'muted' ?>"><?= count($crossRules) ?> aturan</span>
        <?php if (!is_file($crossTargetFile)): ?><span class="tag trash">file belum ada</span><?php endif; ?>
        <span style="flex:1"></span>
        <a class="tag muted" href="bi/" target="_blank" rel="noopener">buka halaman analisis</a>
    </h2>
    <p class="hint">Aturan di bawah <strong>hanya</strong> dipakai halaman analisis
       <em>semua kelompok</em> (lintas grup). Berkasnya bernama
       <code>target_settings.json</code> dan disimpan di
       <code>test/bi/target_settings.json</code>, jadi <strong>tidak</strong> mengambil
       <code>target_settings.json</code> milik grup mana pun. Biarkan kosong bila tidak
       diperlukan &mdash; halaman lintas grup akan memakai perbandingan capaian umum.</p>
    <details class="tgt" data-action="save_targets_all"
             data-file="test/bi/target_settings.json" data-hide-group="1"
             data-note="Aturan target khusus (dipakai halaman analisis semua kelompok):">
        <summary>Editor target lintas grup <code>test/bi/target_settings.json</code>
            <span class="tag <?= $crossRules ? 'mentee' : 'muted' ?>"><?= count($crossRules) ?> aturan</span>
            <?php if (!is_file($crossTargetFile)): ?><span class="tag trash">file belum ada</span><?php endif; ?>
        </summary>
        <script type="application/json" class="tgt-data"><?= adm_targets_json($crossTargets) ?></script>
        <div class="tgt-body"></div>
    </details>
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
        <button class="ghost small" type="button" data-copy-analysis="<?= adm_h($g) ?>"
                data-group="<?= adm_h($g) ?>" data-origin="<?= adm_h(SITE_ORIGIN) ?>"
                title="Salin pesan berisi tautan halaman analisis grup ini">Salin pesan halaman analisis</button>
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
    <?php $tokKind = adm_gh_token_kind(); ?>
    <?php if ($tokKind !== ''): ?>
        <p class="hint">Token <strong><?= $tokKind === 'fine' ? 'fine-grained' : ($tokKind === 'classic' ? 'classic' : 'tidak dikenali') ?></strong>
           tersimpan di <code>test/_manifest/.token</code> (mode 600, tidak ikut ke git/server,
           <?= adm_h(adm_secret_note()) ?>).</p>
        <div class="row" style="margin:10px 0">
            <button class="ghost" data-act="test_gh" data-noreload="1">Uji koneksi</button>
            <button class="ghost" data-act="gh_list" data-noreload="1">Daftar file di repo</button>
            <button class="warn" data-act="set_token" data-empty="1" data-from-field="token">Hapus token</button>
        </div>
    <?php else: ?>
        <p class="hint">Belum ada token. Buat <em>Fine-grained personal access token</em> di GitHub
          (<code>Settings &rsaquo; Developer settings &rsaquo; Personal access tokens &rsaquo; Fine-grained tokens</code>),
          lalu pada <strong>Repository access</strong> pilih <em>Only select repositories</em> dan centang
          <code><?= adm_h(MANIFEST_REPO) ?></code>, dengan izin <strong>Contents: Read and write</strong>.
          Token berawalan <code>github_pat_</code>.</p>
        <p class="hint">Token <em>classic</em> (<code>ghp_...</code>) dengan scope <code>repo</code> juga diterima.</p>
        <div class="row" style="margin:10px 0">
            <input type="text" id="gh-token" placeholder="github_pat_..." autocomplete="off" spellcheck="false">
            <button data-act="set_token" data-from="gh-token" data-from-field="token">Simpan token</button>
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

<details class="panel" id="or-panel"<?= ai_secret_read(AI_KEY_FILE) !== '' ? '' : ' open' ?>>
    <summary style="display:flex;align-items:center;gap:8px">
        <strong style="font-size:1rem">AI OpenRouter &mdash; Susun aturan target otomatis</strong>
        <span class="tag muted" id="or-status"><?= ai_secret_read(AI_KEY_FILE) !== '' ? 'kunci tersimpan' : 'belum dikonfigurasi' ?></span>
        <span style="flex:1"></span>
        <span class="hint" style="font-size:.75rem"><?= ai_secret_read(AI_KEY_FILE) !== '' ? 'Panel dikecilkan karena kunci sudah aman. Klik untuk membuka.' : 'Isi kunci OpenRouter untuk mulai menyusun aturan.' ?></span>
    </summary>

    <p class="hint">Kunci OpenRouter disimpan di <code>test/_ai/openrouter_key</code> (mode 600, tidak ikut
       ke git/server). Model gratis dipilih otomatis; penyusunan aturan dilakukan di dalam editor
       <em>target_settings.json</em> tiap grup dan di panel "Target khusus &mdash; Analisis Semua Kelompok".
       Panel ini untuk mengelola koneksi.</p>
    <h3 style="font-size:.95rem;margin:18px 0 8px;color:var(--muted)">1. Koneksi OpenRouter &amp; GitHub</h3>
    <div class="row" style="margin:10px 0">
        <input type="password" id="or-key" placeholder="sk-or-... (OpenRouter)" autocomplete="off" spellcheck="false">
        <input type="text" id="or-gh" placeholder="github_pat_... untuk buat issue, kosongkan bila belum ada" autocomplete="off" spellcheck="false">
        <input type="text" id="or-repo" placeholder="owner/repo (kosong = git-ftp-amalanyumiyah)" autocomplete="off" spellcheck="false">
    </div>
    <div class="row" style="margin:10px 0">
        <button data-act="or_save_conn" data-noreload="1" data-msg-to="or-msg" data-extra="or-key:or_key,or-gh:gh_token,or-repo:issue_repo">Simpan koneksi</button>
        <button class="ghost" data-act="or_test_conn" data-noreload="1" data-msg-to="or-msg">Uji koneksi</button>
        <button class="warn" data-act="or_clear_conn" data-msg-to="or-msg">Hapus kunci</button>
    </div>
    <div id="or-msg"></div>

    <h3 style="font-size:.95rem;margin:18px 0 8px;color:var(--muted)">2. Susun aturan dari deskripsi</h3>
    <p class="hint">Buka editor target (panel grup atau "Target khusus &mdash; Analisis Semua Kelompok"),
       isi deskripsi rules di kolom AI, lalu klik <strong>Susun dengan AI</strong>. Hasil diisi ke editor;
       klik <strong>Simpan target</strong> untuk menyimpannya.</p>
</details>

<?php $secBadge = adm_secret_badge(); ?>
<details class="panel" id="sec-panel"<?= adm_secret_needs_attention() ? ' open' : '' ?>>
    <summary style="display:flex;align-items:center;gap:8px">
        <strong style="font-size:1rem">Keamanan rahasia &mdash; status token &amp; API key</strong>
        <span class="tag <?= adm_h($secBadge['class']) ?>" id="sec-badge"><?= adm_h($secBadge['text']) ?></span>
        <span style="flex:1"></span>
        <span class="hint" style="font-size:.75rem">Token GitHub &amp; kunci OpenRouter</span>
    </summary>

    <p class="hint">Token &amp; API key disimpan sebagai berkas di server
       (<code>test/_manifest/.token</code>, <code>test/_ai/openrouter_key</code>,
       <code>test/_ai/github_token</code>). Bila masih <strong>plaintext</strong>, siapa pun yang bisa
       membaca berkas server dapat melihatnya. <strong>Enkripsi sekali klik</strong>: kunci enkripsi dibuat
       lalu ditambal ke <code>test/admin.json</code> (terbungkus, tidak ditulis apa adanya), dan tiap berkas
       ditulis ulang sebagai <strong>AES-256-GCM</strong>. Berkas rahasia tidak ikut ke git maupun FTP.</p>

    <div class="row" style="margin:10px 0">
        <button class="ghost" data-act="sec_status" data-noreload="1" data-html-to="sec-status" data-msg-to="sec-msg">Cek status</button>
        <button data-act="sec_encrypt" data-noreload="1" data-html-to="sec-status" data-msg-to="sec-msg">Enkripsi semua</button>
    </div>
    <div id="sec-status"><?= adm_render_secret_status() ?></div>
    <div id="sec-msg"></div>
</details>

<?php nickname_render($root); ?>
</div>

<script>
const msgs = document.getElementById('msgs');
function show(list){
    msgs.innerHTML = '';
    (list||[]).forEach(m=>{
        const li = document.createElement('li');
        li.className = m.type || '';
        li.textContent = m.text;
        msgs.appendChild(li);
    });
    // Geser ke area pesan supaya hasilnya terlihat walau tombol diklik jauh di bawah.
    if ((list||[]).length) msgs.scrollIntoView({block:'center', behavior:'smooth'});
}
// Daftar file dari aksi 'gh_list' (dikirim server sebagai d.files).
function renderFiles(files){
    const box = document.getElementById('gh-files');
    if (!box) return;
    if (!files || !files.length){
        box.innerHTML = '<p class="hint">Tidak ada file <code>manifest_*.json</code> di repo.</p>';
        return;
    }
    box.textContent = '';
    const ul = document.createElement('ul');
    ul.className = 'gh-file-list';
    files.forEach(f => {
        const li = document.createElement('li');
        const a = document.createElement('a');
        a.href = f.url; a.target = '_blank'; a.rel = 'noopener';
        a.textContent = f.name;
        li.appendChild(a);
        li.appendChild(document.createTextNode(' · ' + f.size + ' B'));
        ul.appendChild(li);
    });
    box.appendChild(ul);
    box.scrollIntoView({block:'center', behavior:'smooth'});
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

    const btn = el.tagName === 'BUTTON' ? el : null;
    const btnOrig = btn ? {disabled: btn.disabled, text: btn.textContent} : null;
    if (btn){
        btn.disabled = true;
        btn.textContent = 'Menyimpan…';
    }

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
        const v = input ? input.value.trim() : '';
        const field = el.dataset.fromField || 'name';
        if (!v){
            show([{type:'err', text: field === 'name' ? 'Nama tidak boleh kosong.' : 'Isian tidak boleh kosong.'}]);
            if (btn){ btn.disabled = btnOrig.disabled; btn.textContent = btnOrig.text; }
            return;
        }
        body.set(field, v);
        // Kolom nama dikosongkan setelah dipakai (buat grup/mentee). Kolom token
        // dibiarkan agar mudah diperbaiki bila verifikasi gagal.
        if (field === 'name') input.value = '';
    } else if (el.dataset.empty !== undefined){
        body.set(el.dataset.fromField || 'name', '');
    }

    // data-extra="id1:field1,id2:field2" kirim banyak isian sekaligus
    // (nilai kosong tetap dikirim).
    if (el.dataset.extra){
        for (const pair of String(el.dataset.extra).split(',')){
            const kv = pair.split(':');
            const input = document.getElementById(kv[0]);
            if (input) body.set(kv[1] || kv[0], input.value.trim());
        }
    }

    // Umpan balik segera: panggilan GitHub bisa perlu beberapa detik, supaya
    // tombol tidak terasa "tidak menghasilkan apa-apa".
    show([{text:'Memproses…'}]);

    const msgTo = el.dataset.msgTo ? document.getElementById(el.dataset.msgTo) : null;

    fetch('', {method:'POST', body, headers:{'Content-Type':'application/x-www-form-urlencoded'}})
        .then(r => r.json())
        .then(d => {
            show(d.messages);
            // Juga tampilkan notifikasi di elemen target (mis. #or-msg) agar
            // hasil terlihat di panel tempat tombol diklik, tidak hanya atas halaman.
            if (msgTo && d.messages){
                msgTo.innerHTML = '';
                d.messages.forEach(m=>{
                    const div = document.createElement('div');
                    div.className = (m.type || 'ok') + ' or-msg-item';
                    div.textContent = m.text;
                    msgTo.appendChild(div);
                });
            }
            if (Array.isArray(d.files)) renderFiles(d.files);
            // HTML status khusus (mis. tabel status rahasia) bila tombol minta.
            if (d.html && el.dataset.htmlTo){
                const box = document.getElementById(el.dataset.htmlTo);
                if (box) box.innerHTML = d.html;
            }
            // Perbarui badge ringkas status rahasia tanpa reload.
            if (d.sec_badge){
                const b = document.getElementById('sec-badge');
                if (b){ b.textContent = d.sec_badge.text; b.className = 'tag ' + d.sec_badge.class; }
            }
            // Update status panel OpenRouter bila relevan.
            if (el.dataset.act === 'or_save_conn' || el.dataset.act === 'or_clear_conn'){
                setOrStatus(el.dataset.act === 'or_save_conn' ? (d.ok ? 'saved' : 'error') : 'cleared');
            }
            // Muat ulang hanya bila berhasil DAN aksi memang mengubah tampilan
            // tabel. Aksi informasi (Uji koneksi, Daftar file) ditandai
            // data-noreload supaya hasilnya tidak langsung hilang.
            if (d.ok && el.dataset.noreload === undefined) setTimeout(() => location.reload(), 700);
            if (btn){ btn.disabled = btnOrig.disabled; btn.textContent = btnOrig.text; }
        })
        .catch(() => {
            show([{type:'err', text:'Gagal menghubungi server.'}]);
            if (msgTo){
                msgTo.innerHTML = '<div class="err or-msg-item">Gagal menghubungi server.</div>';
            }
            if (btn){ btn.disabled = btnOrig.disabled; btn.textContent = btnOrig.text; }
        });
}

// Status panel OpenRouter: menandai apakah kunci & token sudah tersimpan.
// mode: 'saved' = berhasil simpan, 'cleared' = dihapus, 'error' = gagal simpan.
function setOrStatus(mode){
    const status = document.getElementById('or-status');
    const panel  = document.getElementById('or-panel');
    if (!status) return;
    if (mode === 'saved'){
        status.textContent = 'kunci tersimpan';
        status.className = 'tag mentee';
        if (panel) panel.open = false; // tutup panel karena konfigurasi sudah aman
    } else if (mode === 'cleared'){
        status.textContent = 'belum dikonfigurasi';
        status.className = 'tag muted';
        if (panel) panel.open = true;  // buka agar user bisa isi kembali
    } else {
        // error atau default: biarkan teks yang ada dari server (PHP)
    }
}

// ===================== Pencarian cepat (grup · mentee · aturan target) =====================
// Indeks dibangun dari DOM halaman — tanpa permintaan tambahan ke server.
(function(){
    const input = document.getElementById('adm-find-input');
    const out   = document.getElementById('adm-find-out');
    const info  = document.getElementById('adm-find-info');
    if (!input || !out) return;

    const index = [];
    const norm = s => String(s == null ? '' : s).toLowerCase();

    // Grup aktif: tautan pada <h2> panel grup, format ../<grup>/index.php
    document.querySelectorAll('section.panel').forEach(sec => {
        const h2a = sec.querySelector('h2 a[href]');
        const m = h2a ? /\/([^.\/?#]+)\/index\.php$/.exec(h2a.getAttribute('href')) : null;
        if (m) index.push({kind:'Grup', group:m[1], name:m[1], el:sec, sub:'grup'});
    });

    // Mentee aktif: checkbox "Mentor" berisi data-group & data-name
    document.querySelectorAll('[data-act="toggle_mentor"]').forEach(el => {
        if (!el.dataset.name) return;
        const row = el.closest('tr');
        index.push({kind:'Mentee', group:el.dataset.group || '', name:el.dataset.name, el:row || el,
            sub:(el.dataset.group || '') + (el.checked ? ' · mentor' : '')});
    });

    // Isi tempat sampah & yang tidak lagi mentoring
    document.querySelectorAll('[data-act="restore_group"]').forEach(el => {
        index.push({kind:'Sampah', group:el.dataset.group || '', name:el.dataset.group || '',
            el:el.closest('.row') || el, sub:'grup di tempat sampah'});
    });
    document.querySelectorAll('[data-act="restore_mentee"]').forEach(el => {
        if (!el.dataset.name) return;
        index.push({kind:'Sampah', group:el.dataset.group || '', name:el.dataset.name,
            el:el.closest('tr') || el, sub:(el.dataset.group || '') + ' · di tempat sampah'});
    });
    document.querySelectorAll('[data-act="reactivate_mentee"]').forEach(el => {
        if (!el.dataset.name) return;
        index.push({kind:'Sampah', group:'', name:el.dataset.name,
            el:el.closest('tr') || el, sub:'tidak lagi mentoring'});
    });

    // Aturan target: dibaca dari JSON di .tgt-data dalam <details.tgt>
    document.querySelectorAll('details.tgt').forEach(det => {
        let data = null;
        try { data = JSON.parse(det.querySelector('.tgt-data').textContent) || {}; } catch (e) { data = null; }
        const rules = (data && Array.isArray(data.rules)) ? data.rules : [];
        const g = det.dataset.group || (det.dataset.action === 'save_targets_all' ? 'lintas grup' : '');
        rules.forEach(r => {
            const label = norm(r && r.label);
            const cat   = norm(r && r.category);
            if (!label && !cat) return;
            index.push({kind:'Aturan', group:g, name: label ? r.label : r.category, el:det,
                sub: [g, (r && r.category) || ''].filter(Boolean).join(' · ')});
        });
    });

    function counts(){
        const c = {};
        index.forEach(i => { c[i.kind] = (c[i.kind] || 0) + 1; });
        return [c.Grup ? c.Grup + ' grup' : '', c.Mentee ? c.Mentee + ' mentee' : '',
                c.Aturan ? c.Aturan + ' aturan target' : '', c.Sampah ? c.Sampah + ' di sampah' : '']
            .filter(Boolean).join(' · ');
    }

    function clearHit(){
        document.querySelectorAll('.adm-find-hit').forEach(e => e.classList.remove('adm-find-hit'));
    }

    function go(item){
        clearHit();
        if (item.el.tagName === 'DETAILS') item.el.open = true;
        if (item.el.scrollIntoView) item.el.scrollIntoView({behavior:'smooth', block:'center'});
        item.el.classList.add('adm-find-hit');
        setTimeout(() => item.el.classList.remove('adm-find-hit'), 2800);
    }

    function btn(item){
        const b = document.createElement('button');
        b.type = 'button';
        const k = document.createElement('span'); k.className = 'adm-find-k'; k.textContent = item.kind;
        const n = document.createElement('span'); n.className = 'adm-find-n'; n.textContent = item.name;
        const s = document.createElement('span'); s.className = 'adm-find-s'; s.textContent = item.sub || '';
        b.append(k, n, s);
        b.addEventListener('click', () => go(item));
        return b;
    }

    let timer = null;
    function render(q){
        const nq = norm(q);
        out.innerHTML = '';
        info.textContent = nq ? '' : counts();

        if (!nq){ out.hidden = true; return; }
        out.hidden = false;

        const hits = index
            .filter(it => norm(it.name).includes(nq) || norm(it.sub).includes(nq) || norm(it.group).includes(nq))
            .sort((a, b) => (a.kind === b.kind ? 0 : (a.kind === 'Aturan' ? 1 : 0) - (b.kind === 'Aturan' ? 1 : 0))
                        || (a.name.localeCompare(b.name)))
            .slice(0, 80);

        if (!hits.length){
            const p = document.createElement('div');
            p.className = 'adm-find-none';
            p.textContent = 'Tidak ada hasil untuk “' + q + '”.';
            out.appendChild(p);
            return;
        }
        const ul = document.createElement('ul');
        hits.forEach(it => {
            const li = document.createElement('li');
            li.appendChild(btn(it));
            ul.appendChild(li);
        });
        out.appendChild(ul);
        info.textContent = hits.length + (hits.length === 80 ? ' pertama' : '') + ' hasil';
    }

    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => render(input.value), 140);
    });
    input.addEventListener('keydown', e => {
        if (e.key === 'Escape'){ input.value = ''; render(''); }
    });
    // "/" memfokuskan pencarian, kecuali sedang mengetik di kolom lain.
    document.addEventListener('keydown', e => {
        if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
        const t = document.activeElement;
        if (t && /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)) return;
        e.preventDefault();
        input.focus();
        input.select();
    });

    info.textContent = counts();
})();

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
    // Penjelasan singkat tiap mode (ditampilkan di bawah pilihan mode).
    const MODE_DESC = {
        day_presence:      'Hitung berapa hari key terisi (mis. dhuha dikerjakan 3 hari).',
        value_count:       'Hitung berapa kali sebuah nilai muncul pada key (mis. "M" pada sholat wajib).',
        filled_key_count:  'Hitung berapa banyak key yang terisi dalam periode ini.',
        rawatib_count:     'Hitung jumlah rakaat dari centang rawatib.',
        numeric:           'Pakai ambang angka tertentu sebagai target.',
        quran_sheet_count: 'Hitung lembar Al-Qur\u2019an dari halaman yang dicatat.',
    };
    const ALL_KEYS = ['subuh','dzuhur','ashar','maghrib','isya','rawatib','dhuha','tahajud','witir',
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

    // Pemilih data keys: chip yang bisa diklik.
    function keysEditor(selected, onChange){
        const chosen = new Set((selected||[]).filter(k => ALL_KEYS.includes(k)));
        const chips = mk('div',{class:'tgt-keys'});
        ALL_KEYS.forEach(k => {
            const b = mk('button',{type:'button', class:'tgt-chip' + (chosen.has(k) ? ' on' : '')}, k);
            b.addEventListener('click', () => {
                if (chosen.has(k)) { chosen.delete(k); b.classList.remove('on'); }
                else               { chosen.add(k);    b.classList.add('on'); }
                if (onChange) onChange(Array.from(chosen));
            });
            chips.appendChild(b);
        });
        const box = mk('div',{class:'tgt-keys-box'}, chips);
        box._read = () => Array.from(chosen);
        return box;
    }

    // Nilai match_value yang mungkin, mengikuti Data keys yang dipilih.
    function matchValueOptions(keys){
        const sholat   = ['subuh','dzuhur','ashar','maghrib','isya'];
        const checkbox = ['dhuha','tahajud','witir','senin_kamis','ayamul_bidh','sedekah','almatsurat_pagi','almatsurat_petang'];
        const hasSholat   = keys.some(k => sholat.includes(k));
        const hasCheckbox = keys.some(k => checkbox.includes(k));
        const opts = [];
        if (hasSholat) {
            opts.push(['M',   'M — Masjid (Jamaah)']);
            opts.push(['R',   'R — Rumah (Sendiri)']);
            opts.push(['R-J', 'R-J — Rumah (Jamaah)']);
            opts.push(['M-S', 'M-S — Masjid (Sendiri)']);
            opts.push(['Q',   'Q — Qadha']);
        }
        if (hasCheckbox) opts.push(['\u2713', '\u2713 — Sudah dilakukan']);
        return opts;
    }

    // Pemilih match_value: chip dinamis (bukan ketik bebas) sesuai Data keys.
    function matchValueEditor(initial, getKeys){
        const chosen = new Set(String(initial||'').split(',').map(s => s.trim()).filter(Boolean));
        const chips  = mk('div',{class:'tgt-keys'});
        const note   = mk('small',{class:'tgt-mv-note'});
        const box    = mk('div',{class:'tgt-mv'}, chips, note);

        function rebuild(){
            const opts    = matchValueOptions(getKeys());
            const allowed = new Set(opts.map(o => o[0]));
            if (opts.length) {
                Array.from(chosen).forEach(v => { if (!allowed.has(v)) chosen.delete(v); });
            }
            chips.innerHTML = '';
            if (!opts.length) {
                note.textContent = 'Pilih Data keys dulu \u2014 pilihan nilai muncul otomatis '
                    + '(sholat wajib \u2192 M/R/R-J/M-S/Q, kunci centang \u2192 \u2713).';
                return;
            }
            note.textContent = '';
            opts.forEach(([v, t]) => {
                const b = mk('button',{type:'button', class:'tgt-chip' + (chosen.has(v) ? ' on' : ''), title:t}, v);
                b.addEventListener('click', () => {
                    if (chosen.has(v)) { chosen.delete(v); b.classList.remove('on'); }
                    else               { chosen.add(v);    b.classList.add('on'); }
                    box.dispatchEvent(new Event('input',{bubbles:true}));
                });
                chips.appendChild(b);
            });
        }

        box._read    = () => Array.from(chosen).join(',');
        box._refresh = () => { rebuild(); box.dispatchEvent(new Event('input',{bubbles:true})); };
        rebuild();
        return box;
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
        f.period   = sel(PERIODS, rule.period||'day');
        f.target_count = inp('number', rule.target_count != null ? rule.target_count : 1, {min:'0', step:'1'});
        f.mode     = sel(MODES, rule.mode||'day_presence');
        f.target   = inp('text', rule.target||'', {placeholder:'3 kali per hari'});
        f.label.addEventListener('input', () => { title.textContent = f.label.value.trim() || 'Aturan baru'; });

        // Target (teks) terisi otomatis dari jumlah + satuan + periode. Bisa
        // ditimpa manual; tombol "Auto" mengembalikannya ke mode otomatis.
        let targetAuto = !String(rule.target || '').trim();
        function satuan(){
            const m = f.mode.value;
            if (m === 'quran_sheet_count') return 'lembar';
            if (m === 'rawatib_count')     return 'rakaat';
            if (m === 'day_presence' || m === 'filled_key_count') return 'hari';
            return 'kali';
        }
        function syncTarget(){
            if (!targetAuto) return;
            const n = Number(f.target_count.value) || 0;
            const word = (PERIODS.find(p => p[0] === f.period.value) || ['','per hari'])[1];
            f.target.value = n + ' ' + satuan() + ' ' + word;
        }
        f.target_count.addEventListener('input', syncTarget);
        f.period.addEventListener('change', syncTarget);
        f.target.addEventListener('input', () => { targetAuto = false; });

        const modeHint = mk('small',{class:'tgt-modehint', text: MODE_DESC[f.mode.value] || ''});

        card.appendChild(mk('div',{class:'tgt-grid'},
            field('Kategori', f.category),
            field('Label', f.label),
            field('Periode', f.period),
            field('Jumlah target', f.target_count),
            field('Mode hitung', f.mode),
            field('Target (teks)', f.target, 'Terisi otomatis; boleh diubah manual.')
        ));
        card.appendChild(mk('div',{class:'tgt-moderow'},
            modeHint,
            mk('button',{class:'ghost small tgt-auto', type:'button',
                title:'Isi otomatis dari jumlah, satuan, dan periode',
                onclick(){ targetAuto = true; syncTarget(); renderPreview(); }}, 'Auto')
        ));

        // Data keys dibuat lebih dulu agar pilihan match_value menyesuaikan.
        let mvRefresh = null;
        const keyBox = keysEditor(rule.data_keys||[], () => { if (mvRefresh) mvRefresh(); });
        const mvBox  = matchValueEditor(rule.match_value||'', () => keyBox._read());
        mvRefresh = () => mvBox._refresh();

        // Field kondisional dibuat sekali, lalu ditampilkan sesuai mode.
        const condEls = {
            match_value:      Object.assign(
                                  field('Nilai dicocokkan (match_value)', mvBox,
                                        'Pilih nilai yang cocok. Pilihan mengikuti Data keys di bawah; boleh lebih dari satu.'),
                                  {_input: mvBox}),
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
        f.mode.addEventListener('change', () => {
            modeHint.textContent = MODE_DESC[f.mode.value] || '';
            renderCond();
            syncTarget();
        });
        renderCond();
        card.appendChild(condBox);

        // Data keys: chip yang bisa diklik (dinamis).
        card.appendChild(field('Data keys', keyBox, 'Klik untuk memilih kunci data yang dihitung.'));

        const detailEl = mk('textarea'); detailEl.value = rule.detail || '';
        card.appendChild(field('Detail', detailEl));

        // Ringkasan langsung, supaya hasil pengaturan terlihat tanpa menebak.
        const preview = mk('div',{class:'tgt-preview'});
        card.appendChild(preview);

        function renderPreview(){
            const out = [];
            if (f.category.value.trim()) out.push(f.category.value.trim());
            if (f.target.value.trim())   out.push(f.target.value.trim());
            out.push('mode: ' + f.mode.value);
            (MODE_FIELDS[f.mode.value]||[]).forEach(k => {
                if (k === 'match_value') {
                    const v = condEls[k]._input._read();
                    if (v) out.push(k + ': ' + v);
                    return;
                }
                const el = condEls[k].querySelector('input, select, textarea');
                if (el && String(el.value).trim() !== '') out.push(k + ': ' + el.value);
            });
            const keys = keyBox._read();
            if (keys.length) out.push('keys: ' + keys.join(', '));
            preview.textContent = (f.label.value.trim() ? '\u201c' + f.label.value.trim() + '\u201d \u2014 ' : '') + out.join(' \u2022 ');
        }
        card.addEventListener('input', renderPreview);
        card.addEventListener('click', renderPreview);

        syncTarget();
        renderPreview();

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
                data_keys: keyBox._read(),
            };
            (MODE_FIELDS[m]||[]).forEach(k => {
                if (k === 'match_value') {
                    out[k] = condEls[k]._input._read();
                    if (out[k] === '') delete out[k];
                    return;
                }
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

        // Editor dipakai dua konteks: target per grup (save_targets) dan target
        // khusus lintas grup (save_targets_all). Konteksnya dibaca dari data-*
        // pada <details class="tgt">, jadi satu editor bisa dipakai keduanya.
        const action    = root.dataset.action || 'save_targets';
        const fileLabel = root.dataset.file || ((root.dataset.group || 'grup') + '/target_settings.json');
        const hideGroup = root.dataset.hideGroup === '1';
        const noteText  = root.dataset.note || 'Aturan target (dipakai halaman analisis grup):';

        const body = root.querySelector('.tgt-body');
        body.innerHTML = '';

        const tf = {
            // Untuk target lintas grup, field "Group" tidak relevan (kotak
            // disembunyikan) tapi nilainya tetap dipakai agar format berkas sama.
            group:    inp(hideGroup ? 'hidden' : 'text', data.group || (hideGroup ? 'LINTAS GRUP' : '')),
            tag:      inp('text', data.tag||''),
            title:    inp('text', data.title||''),
            greeting: inp('text', data.greeting||''),
            intro:    inp('text', data.intro||''),
            closing:  inp('text', data.closing||''),
        };
        body.appendChild(mk('div',{class:'tgt-grid'},
            hideGroup ? tf.group : field('Group', tf.group),
            field('Tag', tf.tag),
            field('Judul', tf.title), field('Sapaan', tf.greeting)
        ));
        body.appendChild(mk('div',{class:'tgt-grid'},
            field('Intro', tf.intro), field('Closing', tf.closing)
        ));

        body.appendChild(mk('hr',{class:'tgt-sep'}));
        body.appendChild(mk('div',{class:'tgt-note', text: noteText}));

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
            post.set('action', action);
            // save_targets butuh nama grup; save_targets_all menulis ke berkas
            // lintas grup sendiri, jadi grup tidak dikirim.
            if (action === 'save_targets') post.set('group', root.dataset.group || '');
            post.set('settings', JSON.stringify(settings));
            fetch('', {method:'POST', body:post, headers:{'Content-Type':'application/x-www-form-urlencoded'}})
                .then(r => r.json())
                .then(d => { show(d.messages); setTimeout(() => location.reload(), d.ok ? 700 : 300); })
                .catch(() => show([{type:'err', text:'Gagal menghubungi server.'}]));
        }

        // ---- Susun aturan dengan AI (OpenRouter mode gratis) ----
        // Ditempatkan SETELAH daftar aturan supaya tampilan penginputan utama
        // tidak bergeser. Hasil AI diisi ke editor ini, lalu disimpan seperti biasa.
        const aiDesc = mk('textarea', {
            class: 'tgt-ai-desc', rows: '3',
            placeholder: 'Tulis deskripsi rules, misalnya: sholat 5 waktu jamaah di masjid tiap hari, '
                + 'rawatib 4 rakaat per hari, tilawah 1 lembar per hari, puasa 4x per bulan, '
                + 'sedekah tiap pekan, istighfar 100x sehari…'
        });
        const aiBtn = mk('button', {type:'button'}, 'Susun dengan AI');
        const aiOut = mk('div', {class:'tgt-ai-out'});
        body.appendChild(mk('div', {class:'tgt-ai'},
            mk('div', {class:'tgt-note', text:'Aturan otomatis dari deskripsi (AI):'}),
            aiDesc,
            mk('div', {class:'tgt-ai-row'}, aiBtn,
                mk('span', {class:'tgt-note',
                    text:'OpenRouter mode gratis — model dipilih otomatis; bila satu model ditolak, model gratis berikutnya dicoba.'})),
            aiOut
        ));

        function aiClear(){
            const box = root.querySelector('.tgt-ai-out');
            if (box) box.textContent = '';
        }
        function aiNote(text, cls){
            const box = root.querySelector('.tgt-ai-out');
            if (!box) return;
            const div = document.createElement('div');
            div.className = 'tgt-ai-msg' + (cls ? ' ' + cls : '');
            div.textContent = text;
            box.appendChild(div);
        }

        aiBtn.addEventListener('click', () => {
            const desc = aiDesc.value.trim();
            if (desc.length < 10){
                show([{type:'err', text:'Tulis deskripsi rules dulu (minimal 10 karakter).'}]);
                aiDesc.focus();
                return;
            }
            const hadRules = list.querySelectorAll('.tgt-rule').length > 0;
            aiClear();
            aiBtn.disabled = true;
            aiBtn.textContent = 'Menyusun…';
            show([{text:'AI sedang menyusun rules — model gratis dicoba satu per satu, bisa ±20 detik…'}]);

            const post = new URLSearchParams();
            post.set('action', 'or_generate');
            post.set('desc', desc);
            post.set('cross', action === 'save_targets_all' ? '1' : '0');

            fetch('', {method:'POST', body:post, headers:{'Content-Type':'application/x-www-form-urlencoded'}})
                .then(r => r.json())
                .then(d => {
                    aiBtn.disabled = false;
                    aiBtn.textContent = 'Susun dengan AI';
                    show(d.messages);

                    if (d.ok && d.settings){
                        const nRules = Array.isArray(d.settings.rules) ? d.settings.rules.length : 0;
                        const model  = d.model || '?';
                        let note = 'Model ' + model + ' menyusun ' + nRules + ' aturan dari deskripsi ini.';
                        if (d.feedback) note += '\n' + d.feedback;

                        // Isi editor dengan hasil AI, lalu gambar ulang.
                        try { script.textContent = JSON.stringify(d.settings); } catch (e) {}
                        build(root);
                        const freshDesc = root.querySelector('.tgt-ai-desc');
                        if (freshDesc) freshDesc.value = desc;

                        const doSave = () => {
                            try {
                                sessionStorage.setItem('adm-ai-note',
                                    'AI (' + model + ') menyusun ' + nRules + ' aturan dan menyimpannya ke '
                                    + fileLabel + '. Periksa hasilnya; klik "Simpan target" lagi setelah diperbaiki.');
                            } catch (e) {}
                            const sb = Array.from(root.querySelectorAll('.tgt-actions button'))
                                .find(b => b.textContent.trim() === 'Simpan target');
                            if (sb) sb.click();
                        };

                        if (!hadRules){ doSave(); return; }

                        if (window.confirm(
                            'Hasil AI: ' + nRules + ' aturan (' + model + ').\n\n'
                            + fileLabel + ' sudah berisi aturan.\n'
                            + 'OK = timpa & simpan sekarang.\n'
                            + 'Batal = biarkan di editor (belum tersimpan).')){
                            doSave(); return;
                        }
                        aiNote(note + '\n\nBelum disimpan. Klik "Simpan target" bila hasil ini mau dipertahankan, '
                            + 'atau muat ulang halaman untuk membatalkan.', 'ok');
                        return;
                    }

                    if (d.feedback){
                        // Rules tidak tercakup struktur target_settings.json:
                        // umpan balik ke halaman admin (+ issue ke repo bila ada token).
                        aiNote('Umpan balik AI — rules belum bisa diwakili struktur target_settings.json:\n'
                               + d.feedback, 'err');
                        if (d.issue){
                            if (d.issue.ok && d.issue.url){
                                aiNote('Issue dibuat di repository proyek: #' + d.issue.number + ' — ' + d.issue.url, 'ok');
                            } else {
                                aiNote('Issue BELUM dibuat: ' + (d.issue.error || 'penyebab tidak diketahui')
                                       + '\nSalin umpan balik di atas dan buat issue secara manual.', 'err');
                            }
                        }
                        return;
                    }

                    aiNote(d.error || 'Gagal menyusun rules. Coba lagi atau ubah deskripsinya.', 'err');
                })
                .catch(() => {
                    aiBtn.disabled = false;
                    aiBtn.textContent = 'Susun dengan AI';
                    show([{type:'err', text:'Gagal menghubungi server.'}]);
                });
        });

        // ---- Ekspor target_settings.json ke MD / TXT ----
        function downloadBlob(blob, namaFile){
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = namaFile;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }
        function rulesToMd(){
            const rules = Array.isArray(data.rules) ? data.rules : [];
            const lines = [
                '# ' + (data.title || 'Target Settings'),
                '',
                'Group: ' + (data.group || '-'),
                'Tag: ' + (data.tag || '-'),
                '',
                data.greeting ? '> ' + data.greeting : '',
                data.intro ? '' + data.intro : '',
                '',
                '## Aturan',
                '',
                '| Kategori | Label | Target | Periode | Mode | Target Count |',
                '|----------|-------|--------|---------|------|--------------|',
            ];
            rules.forEach(r => {
                lines.push('| ' + (r.category||'-') + ' | ' + (r.label||'-') + ' | ' + (r.target||'-') + ' | ' + (r.period||'-') + ' | ' + (r.mode||'-') + ' | ' + (r.target_count||0) + ' |');
            });
            lines.push('');
            if (data.closing) lines.push('*' + data.closing + '*');
            return lines.join('\n') + '\n';
        }
        function rulesToTxt(){
            const rules = Array.isArray(data.rules) ? data.rules : [];
            const lines = [
                'TARGET SETTINGS: ' + (data.title || 'Target Settings'),
                'Group: ' + (data.group || '-'),
                'Tag: ' + (data.tag || '-'),
                '',
                data.greeting ? data.greeting : '',
                data.intro ? data.intro : '',
                '',
                'ATURAN:',
                '',
            ];
            rules.forEach((r, i) => {
                lines.push((i+1) + '. ' + (r.label||'-') + ' [' + (r.category||'-') + ']');
                lines.push('   Target: ' + (r.target||'-') + ' | Periode: ' + (r.period||'-') + ' | Mode: ' + (r.mode||'-') + ' | Target Count: ' + (r.target_count||0));
                if (r.match_value) lines.push('   Match Value: ' + r.match_value);
                if (r.threshold != null) lines.push('   Threshold: ' + r.threshold);
                if (r.data_keys && r.data_keys.length) lines.push('   Data Keys: ' + r.data_keys.join(', '));
                lines.push('');
            });
            if (data.closing) lines.push(data.closing);
            return lines.join('\n') + '\n';
        }

        body.appendChild(mk('div',{class:'tgt-actions'},
            mk('button',{class:'ghost', type:'button', onclick(){ list.appendChild(ruleCard({})); }}, '+ Tambah aturan'),
            mk('button',{type:'button', onclick: save}, 'Simpan target'),
            mk('button',{class:'ghost', type:'button', onclick(){ downloadBlob(new Blob([rulesToMd()],{type:'text/markdown'}), (data.group||'target')+'.md'); }}, 'Ekspor MD'),
            mk('button',{class:'ghost', type:'button', onclick(){ downloadBlob(new Blob([rulesToTxt()],{type:'text/plain'}), (data.group||'target')+'.txt'); }}, 'Ekspor TXT'),
            mk('button',{class:'warn small', type:'button', onclick(){
                const isCross = action === 'save_targets_all';
                const nama = isCross ? 'lintas grup (test/bi/target_settings.json)' : "grup '" + (root.dataset.group||'') + "'";
                if (!window.confirm('Hapus target_settings.json ' + nama + '?\n\nAturan akan hilang dan kartu target tidak akan muncul di halaman penginputan.')) return;
                const post = new URLSearchParams();
                post.set('action', isCross ? 'delete_targets_all' : 'delete_targets');
                if (!isCross) post.set('group', root.dataset.group || '');
                fetch('', {method:'POST', body:post, headers:{'Content-Type':'application/x-www-form-urlencoded'}})
                    .then(r => r.json())
                    .then(d => {
                        show(d.messages);
                        if (d.ok) setTimeout(() => location.reload(), 700);
                    })
                    .catch(() => show([{type:'err', text:'Gagal menghubungi server.'}]));
            }}, 'Hapus target'),
            mk('span',{class:'tgt-note', text:'File: ' + fileLabel})
        ));
    }

    document.querySelectorAll('details.tgt').forEach(build);
})();

// Catatan hasil AI disimpan di sessionStorage sebelum "Simpan target" memuat
// ulang halaman, sehingga catatan tetap terlihat setelah reload.
(function(){
    try {
        const note = sessionStorage.getItem('adm-ai-note');
        if (!note) return;
        sessionStorage.removeItem('adm-ai-note');
        show([{type:'ok', text: note}]);
    } catch (e) {}
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

    // Pesan untuk halaman ANALISIS grup (dipakai mentor/pengurus).
    function analysisMessage(group, origin){
        const url = String(origin || location.origin).replace(/\/+$/, '') + '/' + group + '/index.php';
        return "Assalamu'alaikum warahmatullahi wabarakatuh\n"
             + '\n'
             + 'Berikut tautan halaman analisis amalan yaumiyah grup untuk memantau capaian bersama.\n'
             + '\n'
             + 'Amalan Yaumiyah \u2014 Analisis Grup\n'
             + 'Grup   : *```' + group + '```*\n'
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

    // Tombol "Salin pesan halaman analisis": langsung salin (tanpa popup).
    document.querySelectorAll('[data-copy-analysis]').forEach(btn => {
        btn.addEventListener('click', () => {
            const group = btn.dataset.group || btn.dataset.copyAnalysis || '';
            const origin = btn.dataset.origin || location.origin;
            const text = analysisMessage(group, origin);
            const done = () => {
                const old = btn.textContent;
                btn.textContent = 'Tersalin \u2713';
                btn.classList.add('copied');
                show([{ type: 'ok', text: 'Pesan halaman analisis grup \'' + group + '\' tersalin ke clipboard.' }]);
                setTimeout(() => { btn.textContent = old; btn.classList.remove('copied'); }, 1400);
            };
            copyText(text).then(done).catch(done);
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
