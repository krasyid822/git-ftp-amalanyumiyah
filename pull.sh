#!/usr/bin/env bash
#
# pull.sh - Menarik perubahan terbaru dari server FTP ke repo ini.
#
# Cara pakai:
#   ./pull.sh              -> git ftp pull (merge ke branch sekarang)
#   ./pull.sh init         -> upload semua file & buat .git-ftp.log di server
#   ./pull.sh show         -> lihat commit terakhir yang di-deploy
#   ./pull.sh log          -> riwayat commit sampai commit terakhir di-deploy
#   ./pull.sh status       -> cek status tanpa mengubah apa pun
#   ./pull.sh tail         -> pantau log proses yang sedang jalan
#
# Semua output dicatat ke .git-ftp-pull.log
# Kredensial dibaca dari ~/.netrc, konfigurasi URL dari git config.

set -euo pipefail

cd "$(dirname "$0")"

LOGFILE=".git-ftp-pull.log"

RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; BLUE=$'\033[34m'; NC=$'\033[0m'

ts()   { date '+%H:%M:%S'; }
log_line() {
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" >> "$LOGFILE"
}
info() { printf '%s %b==>%b %s\n'   "$(ts)" "$BLUE"   "$NC" "$*"; log_line "==> $*"; }
ok()   { printf '%s %b OK %b %s\n'  "$(ts)" "$GREEN"  "$NC" "$*"; log_line "OK  $*"; }
warn() { printf '%s %b !  %b %s\n'  "$(ts)" "$YELLOW" "$NC" "$*"; log_line "!   $*"; }
err()  { printf '%s %b ERR%b %s\n'  "$(ts)" "$RED"    "$NC" "$*" >&2; log_line "ERR $*"; }

# Jalankan perintah: tampilkan di layar + catat ke logfile
run() {
    "$@" 2>&1 | tee -a "$LOGFILE"
    return "${PIPESTATUS[0]}"
}

info "Mulai. Log: $LOGFILE"

ACTION="${1:-pull}"
if [ "$#" -gt 0 ]; then shift; fi

# tail tidak butuh prasyarat apa pun
if [ "$ACTION" = "tail" ]; then
    exec tail -f "$LOGFILE"
fi

# --- Prasyarat ---
if ! command -v git-ftp >/dev/null 2>&1; then
    err "git-ftp belum terinstall."
    err "  mkdir -p ~/.local/bin && curl -sSLO https://github.com/git-ftp/git-ftp/archive/refs/heads/master.tar.gz"
    err "  tar xzf master.tar.gz && cp git-ftp-master/git-ftp ~/.local/bin/ && chmod +x ~/.local/bin/git-ftp"
    exit 1
fi

if [ -z "$(git config --local --get git-ftp.url || true)" ]; then
    err "git-ftp.url belum di-set."
    err "  git config --local git-ftp.url ftp://ftpupload.net/amalanyaumiyah.xo.je/htdocs/"
    err "  git config --local git-ftp.user if0_37577629"
    exit 1
fi

# git ftp pull butuh lftp
if [ "$ACTION" = "pull" ] && ! command -v lftp >/dev/null 2>&1; then
    err "git ftp pull membutuhkan lftp."
    err "  sudo pacman -S lftp"
    exit 1
fi

# Repo harus bersih: pull tidak menimpa kerjaan yang belum disimpan
if [ "$ACTION" = "pull" ] && { ! git diff --quiet || ! git diff --cached --quiet; }; then
    warn "Ada perubahan belum di-commit. git ftp pull akan menolak."
    warn "Commit dulu:  git add -A && git commit -m '...'"
fi

git_config() { git config --local --get "$1" 2>/dev/null || echo "(tidak di-set)"; }

info "Aksi      : $ACTION"
info "URL       : $(git_config git-ftp.url)"
info "User      : $(git_config git-ftp.user)"
info "Deploy    : $(git ftp show 2>/dev/null | head -1 || echo 'belum ada .git-ftp.log')"
info "Branch    : $(git rev-parse --abbrev-ref HEAD)"
info "HEAD      : $(git rev-parse --short HEAD)"
echo

# --- Jalankan ---
START=$(date +%s)
EXIT=0
case "$ACTION" in
    init)   run git ftp init   "$@" ;;
    show)   run git ftp show   "$@" ;;
    log)    run git ftp log    "$@" ;;
    status) run git status --short --branch ;;
    pull)
        info "Menarik semua file dari server. Ini bisa memakan waktu lama"
        info "(puluhan menit untuk repo ini) karena memirror SELURUH isi htdocs/."
        info "Pantau dengan: ./pull.sh tail"
        echo
        run git ftp pull "$@" || EXIT=$?
        ;;
    *)
        err "Pakai: $0 [pull|init|show|log|status|tail]"
        exit 1
        ;;
esac

DURASI=$(( $(date +%s) - START ))

echo
if [ "$EXIT" -eq 0 ]; then
    ok "Selesai dalam ${DURASI} detik."
    if [ "$ACTION" = "pull" ]; then
        CONFLICT=$(git diff --name-only --diff-filter=U)
        if [ -n "$CONFLICT" ]; then
            warn "Ada konflik merge:"
            echo "$CONFLICT" | while read -r f; do warn "    $f"; done
            warn "Selesaikan: git add <file> lalu git commit"
        else
            ok "Tidak ada konflik. HEAD sekarang: $(git rev-parse --short HEAD)"
        fi
    fi
else
    err "Gagal (exit $EXIT) setelah ${DURASI} detik."
    err "Cek status repo: git status --short --branch"
    err "Kalau detach:    git checkout main"
fi
exit "$EXIT"
