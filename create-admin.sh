#!/usr/bin/env bash
#
# create-admin.sh - Membuat kredensial login admin (test/admin.json).
#
# Cara kerja:
#   1) Username + password dienkripsi AES-256-GCM ke test/admin.json.
#   2) Kunci kredensial itu TIDAK ditulis apa adanya; ia dibungkus dengan
#      bahan internal yang ditempel (disamarkan) ke test/admin.php.
#   3) Jadi test/admin.json saja tidak cukup untuk membuka kredensial.
#   test/admin.json TIDAK ikut ke GitHub, tetapi IKUT ke server FTP.
#
# Skrip MENOLAK berjalan bila test/admin.json sudah ada.
#
# Cara pakai: ./create-admin.sh   (tanya username & password)

set -euo pipefail

cd "$(dirname "$0")"

CRED_FILE="test/admin.json"
PHP_FILE="test/admin.php"

RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; BLUE=$'\033[34m'; NC=$'\033[0m'
info() { printf '%b==>%b %s\n' "$BLUE" "$NC" "$*"; }
ok()   { printf '%b OK %b%s\n' "$GREEN" "$NC" "$*"; }
warn() { printf '%b !  %b%s\n' "$YELLOW" "$NC" "$*"; }
err()  { printf '%bERR%b %s\n' "$RED" "$NC" "$*" >&2; }

usage() {
    sed -n '3,14p' "$0" | sed 's/^# \{0,1\}//'
}

case "${1:-}" in
    --help|-h) usage; exit 0 ;;
    "") : ;;
    *) err "Opsi tidak dikenal: $1"; usage; exit 1 ;;
esac

# --- Sesuai permintaan: jangan pernah menimpa kredensial yang sudah ada ---
if [ -e "$CRED_FILE" ]; then
    err "File $CRED_FILE sudah ada. Skrip pembuatan TIDAK dijalankan."
    err "Hapus file itu dulu bila benar-benar ingin membuat kredensial baru."
    exit 1
fi

command -v php >/dev/null 2>&1 || { err "PHP belum terinstall (dibutuhkan untuk enkripsi)."; exit 1; }

[ -f "$PHP_FILE" ] || { err "File $PHP_FILE tidak ditemukan."; exit 1; }

info "Membuat kredensial admin baru..."

# --- Username ---
printf 'Username [admin]: '
read -r ADMIN_USER
ADMIN_USER="${ADMIN_USER:-admin}"
[ -n "$ADMIN_USER" ] || { err "Username tidak boleh kosong."; exit 1; }

# --- Password (dua kali, tidak ditampilkan) ---
printf 'Password: '
read -rs ADMIN_PASS; echo
printf 'Ulangi password: '
read -rs ADMIN_PASS2; echo

if [ "$ADMIN_PASS" != "$ADMIN_PASS2" ]; then
    err "Password tidak sama."
    exit 1
fi
if [ "${#ADMIN_PASS}" -lt 8 ]; then
    err "Password minimal 8 karakter."
    exit 1
fi

# --- Enkripsi + simpan lewat PHP (agar format sama dengan test/admin.php) ---
ADMIN_USER="$ADMIN_USER" ADMIN_PASS="$ADMIN_PASS" \
ADMIN_CRED_FILE="$CRED_FILE" ADMIN_PHP_FILE="$PHP_FILE" php <<'PHP'
<?php
$user = (string) getenv('ADMIN_USER');
$pass = (string) getenv('ADMIN_PASS');
$file = (string) getenv('ADMIN_CRED_FILE');
$php  = (string) getenv('ADMIN_PHP_FILE');

/* --- 1) Enkripsi payload kredensial dengan kunci kredensial (acak). --- */
$payload = json_encode([
    'username'      => $user,
    'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$key = random_bytes(32);
$iv  = random_bytes(12);
$tag = '';
$ct  = openssl_encrypt($payload, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
if ($ct === false) {
    fwrite(STDERR, "Gagal mengenkripsi kredensial.\n");
    exit(1);
}

/* --- 2) Bungkus kunci kredensial dengan bahan internal (dari "revisi aset"). --- */
$rev = bin2hex(random_bytes(20));
$mk  = hash('sha256', $rev . '|v1', true); // harus sama dengan adm_cred_secret()
$kiv = random_bytes(12);
$ktg = '';
$kw  = openssl_encrypt($key, 'aes-256-gcm', $mk, OPENSSL_RAW_DATA, $kiv, $ktg);
if ($kw === false) {
    fwrite(STDERR, "Gagal membungkus kunci.\n");
    exit(1);
}

$data = [
    'alg'        => 'aes-256-gcm',
    'created_at' => date('c'),
    'key'        => base64_encode($kw),   // kunci kredensial (terbungkus)
    'key_iv'     => base64_encode($kiv),
    'key_tag'    => base64_encode($ktg),
    'iv'         => base64_encode($iv),
    'tag'        => base64_encode($tag),
    'data'       => base64_encode($ct),   // kredensial terenkripsi
];

$dir = dirname($file);
if (!is_dir($dir)) {
    fwrite(STDERR, "Folder $dir tidak ditemukan.\n");
    exit(1);
}

$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if (file_put_contents($file, $json . "\n") === false) {
    fwrite(STDERR, "Gagal menulis $file.\n");
    exit(1);
}
@chmod($file, 0600);

/* --- 3) Tempel (disamarkan) bahan internal ke admin.php. --- */
$src = (string) file_get_contents($php);
$re  = "/(const\s+ADM_ASSET_REV\s*=\s*')[^']*('\s*;)/";
if (!preg_match($re, $src)) {
    fwrite(STDERR, "Gagal menemukan penanda revisi aset di $php.\n");
    exit(1);
}
$src = preg_replace($re, '${1}' . $rev . '${2}', $src, 1);
if ($src === null || file_put_contents($php, $src) === false) {
    fwrite(STDERR, "Gagal memperbarui $php.\n");
    exit(1);
}
PHP

ok "Kredensial tersimpan di $CRED_FILE (username: $ADMIN_USER)."
ok "Kunci internal telah ditempel (disamarkan) ke $PHP_FILE."
warn "$CRED_FILE tidak ikut ke GitHub, tetapi ikut ke FTP lewat .git-ftp-include."
warn "$PHP_FILE ikut ke GitHub; jaga repo tetap privat agar kunci tidak terbaca publik."
warn "Jalankan ./push.sh untuk mengirimnya ke server."
