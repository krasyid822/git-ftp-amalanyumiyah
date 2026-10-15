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

    return ['ok' => true, 'file' => $file, 'url' => adm_manifest_raw_url($mentee)];
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

// ======================= AKSI (AJAX) =======================
$action = $_POST['action'] ?? '';

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
                copy($tpl . '/target_settings.json', $new . '/target_settings.json');
                $reply['ok'] = true;
                $reply['messages'][] = adm_msg("Grup '$slug' dibuat.");
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
                        } else {
                            $reply['messages'][] = adm_msg('Gagal memulihkan dari tempat sampah.', 'err');
                        }
                    } else {
                        mkdir($dest, 0755, true);
                        copy($tpl . '/index.php', $dest . '/index.php');
                        copy($tpl . '/download.php', $dest . '/download.php');
                        file_put_contents($dest . '/data_amalan.json', "{}\n");
                        $reply['ok'] = true;
                        $reply['messages'][] = adm_msg("Mentee '$slug' dibuat di grup '$group'.");
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
            } else {
                $src = (string) @file_get_contents($file);
                $url = adm_manifest_raw_url($name);

                // Ganti nilai $manifestPath yang ada, atau tambahkan bila belum ada.
                $new = preg_replace(
                    '/\$manifestPath\s*=\s*[\'"][^\'"]*[\'"]\s*;/',
                    '$manifestPath = ' . var_export($url, true) . ';',
                    $src,
                    1,
                    $n
                );

                if ($n === 0) {
                    // Belum ada baris manifestPath -> sisipkan setelah <?php
                    $new = preg_replace('/(<\?php\s*\R)/', "$1\$manifestPath = " . var_export($url, true) . ";\n", $src, 1);
                }

                if ($new !== null && $new !== $src && @file_put_contents($file, $new) !== false) {
                    $reply['ok'] = true;
                    $reply['messages'][] = adm_msg("index.php mentee '$name' diarahkan ke manifest raw.");
                } else {
                    $reply['messages'][] = adm_msg('Gagal menulis index.php. Periksa hak akses.', 'err');
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

            if (!adm_valid_name($group) || !adm_valid_name($name) || !is_dir($src)) {
                $reply['messages'][] = adm_msg('Data di tempat sampah tidak ditemukan.', 'err');
            } elseif (!adm_valid_name($target = trim((string) ($_POST['target'] ?? $group)))
                      || !is_dir($root . '/' . $target)) {
                $reply['messages'][] = adm_msg('Grup tujuan tidak valid.', 'err');
            } else {
                $dest = $root . '/' . $target . '/' . $name;
                if (is_dir($dest)) {
                    $reply['messages'][] = adm_msg("Mentee '$name' sudah ada di grup '$target'.", 'err');
                } elseif (rename($src, $dest)) {
                    $reply['ok'] = true;
                    $reply['messages'][] = adm_msg("'$name' dipulihkan ke grup '$target'.");
                } else {
                    $reply['messages'][] = adm_msg('Gagal memulihkan.', 'err');
                }
            }
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
$trashGroups = is_dir($trashRoot) ? adm_subfolders($trashRoot) : [];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Grup Mentoring</title>
<style>
:root{--bg:#061612;--surface:#0b1f1b;--surface2:#071614;--line:rgba(158,243,208,.14);
      --line2:rgba(158,243,208,.22);--text:#eefbf5;--muted:#97b2a8;--accent:#7df0c5;
      --accent2:#ffd27d;--danger:#ff8b8b;--ok:#8ff0b0}
*{box-sizing:border-box}
body{margin:0;font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--text);padding:28px 22px 70px}
.shell{max-width:1200px;margin:0 auto}
header{margin-bottom:22px}
h1{font-size:1.7rem;margin:0 0 6px}
h2{font-size:1.1rem;margin:0 0 12px}
.sub{color:var(--muted);font-size:.9rem;margin:0}
.panel{background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:18px;margin-bottom:18px}
.row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
input[type=text]{background:#08130f;border:1px solid var(--line2);color:var(--text);border-radius:9px;padding:9px 12px;font:inherit;flex:1;min-width:170px}
input[type=text]:focus{outline:2px solid var(--accent);outline-offset:1px}
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
.msgs{margin:14px 0 0;padding:0;list-style:none}
.msgs li{padding:8px 12px;border-radius:9px;margin-bottom:7px;font-size:.88rem}
.msgs .ok{background:rgba(143,240,176,.1);border:1px solid rgba(143,240,176,.3);color:var(--ok)}
.msgs .err{background:rgba(255,139,139,.1);border:1px solid rgba(255,139,139,.3);color:var(--danger)}
.hint{color:var(--muted);font-size:.82rem;line-height:1.6;margin:10px 0 0}
.empty{color:var(--muted);padding:14px;text-align:center;font-size:.9rem}
code{background:#08130f;padding:1px 5px;border-radius:4px;font-size:.85em}
</style>
</head>
<body>
<div class="shell">
<header>
    <h1>Admin Grup Mentoring</h1>
    <p class="sub">Grup dibaca dari folder di root (kecuali <code>test</code>). Mentee berada di dalam folder grup.
       Folder <code>.DEPRECATED</code> adalah tempat sampah dan dapat dipulihkan.</p>
</header>

<ul class="msgs" id="msgs"></ul>

<section class="panel">
    <h2>Buat Grup Baru</h2>
    <div class="row">
        <input type="text" id="g-name" placeholder="Nama grup, misal: FTI">
        <button data-act="create_group" data-from="g-name">Buat Grup</button>
    </div>
    <p class="hint">Folder dibuat sebagai <code>&lt;root&gt;/&lt;nama&gt;/</code> beserta <code>index.php</code> dan <code>target_settings.json</code>.</p>
</section>

<?php foreach ($groups as $g):
    $mentes = adm_mentees($root . '/' . $g);
    $mentor = null;
    foreach ($mentes as $m) { if ($m['isMentor']) { $mentor = $m['name']; break; } }
?>
<section class="panel">
    <h2><a href="../<?= adm_h($g) ?>/index.php" style="color:var(--text);text-decoration:none"><?= adm_h($g) ?></a>
        <span class="tag muted"><?= count($mentes) ?> mentee</span>
        <?php if ($mentor): ?><span class="tag mentor">mentor: <?= adm_h($mentor) ?></span><?php endif; ?>
    </h2>
    <div class="row" style="margin-bottom:14px">
        <input type="text" id="m-<?= adm_h($g) ?>" placeholder="Nama mentee baru di grup <?= adm_h($g) ?>">
        <button data-act="create_mentee" data-group="<?= adm_h($g) ?>" data-from="m-<?= adm_h($g) ?>">Tambah Mentee</button>
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
</section>
<?php endforeach; ?>

<section class="panel">
    <h2>Tempat Sampah <span class="tag trash"><?= count($trashGroups) ?> grup</span></h2>
    <p class="hint">Mentee di sini tidak aktif, tapi datanya masih utuh dan bisa dipulihkan.
       Saat menambah mentee dengan nama yang sama, folder di sini otomatis dipulihkan.</p>

    <?php if (!$trashGroups): ?>
        <div class="empty">Tempat sampah kosong.</div>
    <?php else: ?>
        <?php foreach ($trashGroups as $tg): ?>
            <h3 style="font-size:.95rem;margin:16px 0 8px;color:var(--muted)"><?= adm_h($tg) ?></h3>
            <table>
                <tbody>
                <?php foreach (adm_mentees($trashRoot . '/' . $tg) as $name => $m): ?>
                    <tr>
                        <td><?= adm_h($name) ?></td>
                        <td><?= $m['days'] ?> hari</td>
                        <td><?= $m['isMentor'] ? '<span class="tag mentor">Mentor</span>' : '' ?></td>
                        <td style="width:1%">
                            <button class="ghost" data-act="restore_mentee" data-group="<?= adm_h($tg) ?>" data-name="<?= adm_h($name) ?>">Pulihkan</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

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
       Tombol <strong>Arahkan index</strong> menuliskan raw URL ke <code>index.php</code> mentee.</p>

    <h3 style="font-size:.95rem;margin:18px 0 8px;color:var(--muted)">3. Isi repo (dibaca dari GitHub)</h3>
    <div id="gh-files"><p class="hint">Klik <strong>Daftar file di repo</strong> untuk memuat isi repository.</p></div>

    <h3 style="font-size:.95rem;margin:18px 0 8px;color:var(--muted)">Catatan</h3>
    <ul class="hint" style="padding-left:20px;margin:8px 0">
        <li>Raw URL punya cache ±2 menit setelah commit. Untuk jenama ulang: hapus PWA dari layar utama, lalu buka lagi.</li>
        <li>Manifest ditulis ulang tiap kali tombol ditekan. Nilai yang diubah manual di GitHub akan tertimpa.</li>
        <li>Semua file yang tidak diawali <code>manifest_</code> di repo itu akan diabaikan dan tidak disentuh.</li>
    </ul>
</section>
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
    el.addEventListener('change', e => doAction(e.target));
    el.addEventListener('click', e => doAction(e.target));
});
function doAction(el){
    const body = new URLSearchParams();
    body.set('action', el.dataset.act);
    if (el.dataset.group) body.set('group', el.dataset.group);
    if (el.dataset.name)  body.set('name', el.dataset.name);

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
</script>
</body>
</html>
