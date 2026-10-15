#!/usr/bin/env bash
#
# run.sh - Menjalankan proyek ini dengan server bawaan PHP.
#
# Cara pakai:
#   ./run.sh              -> jalankan di port 8000, buka browser
#   ./run.sh 8080         -> jalankan di port 8080
#   ./run.sh --no-open    -> jalankan tanpa membuka browser
#   ./run.sh --lint       -> cek syntax PHP semua file, lalu keluar
#   ./run.sh --list       -> tampilkan URL yang tersedia, lalu keluar
#   ./run.sh --help       -> bantuan

set -euo pipefail

cd "$(dirname "$0")"

HOST="${HOST:-127.0.0.1}"
PORT="8000"
OPEN_BROWSER=1

RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; BLUE=$'\033[34m'; NC=$'\033[0m'
info() { printf '%b==>%b %s\n' "$BLUE" "$NC" "$*"; }
ok()   { printf '%b OK %b%s\n' "$GREEN" "$NC" "$*"; }
warn() { printf '%b !  %b%s\n' "$YELLOW" "$NC" "$*"; }
err()  { printf '%b ERR%b %s\n' "$RED" "$NC" "$*" >&2; }

usage() {
    sed -n '3,12p' "$0" | sed 's/^# \{0,1\}//'
}

# Daftar URL yang bisa dibuka saat server jalan
list_urls() {
    local base="http://$HOST:$PORT"
    echo
    printf '%sHalaman:%s\n' "$GREEN" "$NC"
    printf '  %-46s %s\n' "$base/test/index.php" "aplikasi utama (dashboard)"
    printf '  %-46s %s\n' "$base/test/bi/" "analisis lintas semua grup"
    printf '  %-46s %s\n' "$base/test/admin.php" "kelola grup & mentee (admin)"

    echo
    printf '%sDashboard grup:%s\n' "$GREEN" "$NC"
    local ada=0
    for g in */; do
        g="${g%/}"
        case "$g" in test|.*) continue ;; esac
        [ -f "$g/index.php" ] || continue
        printf '  %-46s %s\n' "$base/$g/index.php" "grup $g"
        ada=1
    done
    [ "$ada" -eq 1 ] || warn "belum ada grup di root."

    echo
    printf '%sHalaman mentee:%s\n' "$GREEN" "$NC"
    ada=0
    for g in */; do
        g="${g%/}"
        case "$g" in test|.*) continue ;; esac
        [ -f "$g/index.php" ] || continue
        for m in "$g"/*/; do
            [ -d "$m" ] || continue
            m="$(basename "$m")"
            printf '  %-46s %s\n' "$base/$g/$m/index.php" "data $m"
            printf '  %-46s %s\n' "$base/$g/$m/download.php" "unduh $m"
            ada=1
        done
    done
    [ "$ada" -eq 1 ] || warn "belum ada mentee di grup mana pun."
    echo
}

# Cek syntax semua file PHP
lint_all() {
    local gagal=0 total=0
    while IFS= read -r f; do
        total=$((total + 1))
        if ! out=$(php -l "$f" 2>&1); then
            gagal=$((gagal + 1))
            err "$f"
            echo "$out" | sed 's/^/     /'
        fi
    done < <(find . -name '*.php' -not -path './.git/*')

    echo
    if [ "$gagal" -eq 0 ]; then
        ok "$total file PHP, tidak ada error syntax."
    else
        err "$gagal dari $total file PHP bermasalah."
        return 1
    fi
}

# --- Parse argumen ---
while [ "$#" -gt 0 ]; do
    case "$1" in
        --help|-h) usage; exit 0 ;;
        --lint)     ACTION_LINT=1; shift ;;
        --list)     ACTION_LIST=1; shift ;;
        --no-open)  OPEN_BROWSER=0; shift ;;
        -*)         err "Opsi tidak dikenal: $1"; usage; exit 1 ;;
        *)          PORT="$1"; shift ;;
    esac
done

# --- Prasyarat ---
command -v php >/dev/null 2>&1 || {
    err "PHP belum terinstall."
    err "  Arch:  sudo pacman -S php"
    err "  Ubuntu: sudo apt install php-cli"
    exit 1
}

if [ -n "${ACTION_LINT:-}" ]; then
    info "Memeriksa syntax PHP..."
    lint_all
    exit $?
fi

if [ -n "${ACTION_LIST:-}" ]; then
    list_urls
    exit 0
fi

# --- Jalankan server ---
info "Menjalankan PHP built-in server di http://$HOST:$PORT"
info "Tekan Ctrl+C untuk menghentikan."
echo

if [ "$OPEN_BROWSER" -eq 1 ] && command -v xdg-open >/dev/null 2>&1; then
    ( sleep 1.5; xdg-open "http://$HOST:$PORT/test/index.php" >/dev/null 2>&1 || true ) &
fi

php -S "$HOST:$PORT" -t .
