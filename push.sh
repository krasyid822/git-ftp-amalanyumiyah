#!/usr/bin/env bash
#
# push.sh - Upload perubahan ke GitHub dan ke server FTP.
#
# Cara pakai:
#   ./push.sh              -> commit + push GitHub + push FTP
#   ./push.sh github       -> hanya push ke GitHub
#   ./push.sh ftp          -> hanya push ke FTP (commit dulu!)
#   ./push.sh commit "msg" -> commit saja, tanpa push
#   ./push.sh status       -> cek status tanpa mengubah apa pun
#
# Urutan penting: FTP di-push terakhir. Kalau push GitHub sukses tapi FTP
# gagal, perubahan Anda tetap tersimpan di GitHub dan bisa dicoba ulang.
#
# Mode 'all' menaikkan nomor build PWA (test/build_number.txt) satu langkah
# sebelum commit, lalu ikut ter-push ke GitHub + FTP.
#
# Kredensial dibaca dari ~/.netrc, konfigurasi URL dari git config.

set -euo pipefail

cd "$(dirname "$0")"

LOGFILE=".git-ftp-push.log"

RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; BLUE=$'\033[34m'; NC=$'\033[0m'

log_line() {
    printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$1" >> "$LOGFILE"
}
step() { printf '%b==> %b%s\n' "$BLUE" "$NC" "$*";   log_line "==> $*"; }
ok()   { printf '%b OK %b%s\n'  "$GREEN" "$NC" "$*"; log_line "OK  $*"; }
warn() { printf '%b !   %b%s\n' "$YELLOW" "$NC" "$*"; log_line "!   $*"; }
err()  { printf '%b ERR %b%s\n' "$RED" "$NC" "$*" >&2; log_line "ERR $*"; }

# Jalankan perintah: tampilkan di layar + catat ke logfile
run() {
    "$@" 2>&1 | tee -a "$LOGFILE"
    return "${PIPESTATUS[0]}"
}

# Cek prasyarat yang hanya berlaku untuk FTP
need_git_ftp() {
    command -v git-ftp >/dev/null 2>&1 || {
        err "git-ftp belum terinstall."
        err "  mkdir -p ~/.local/bin && curl -sSLO https://github.com/git-ftp/git-ftp/archive/refs/heads/master.tar.gz"
        err "  tar xzf master.tar.gz && cp git-ftp-master/git-ftp ~/.local/bin/ && chmod +x ~/.local/bin/git-ftp"
        exit 1
    }
    [ -n "$(git config --local --get git-ftp.url || true)" ] || {
        err "git-ftp.url belum di-set."
        err "  git config --local git-ftp.url ftp://ftpupload.net/amalanyaumiyah.xo.je/htdocs/"
        exit 1
    }
}

do_commit() {
    local msg="${1:-}"
    if [ -z "$msg" ]; then
        if git diff --quiet && git diff --cached --quiet; then
            warn "Tidak ada perubahan untuk di-commit, lewati."
            return 0
        fi
        msg="update"
    fi
    run git add -A
    run git commit -m "$msg"
    ok "Commit: $msg"
}

do_github() {
    step "Push ke GitHub (origin/main)..."
    run git push origin main
    ok "GitHub tersinkron."
}

# Naikkan nomor build PWA (test/build_number.txt) satu langkah. Dipakai halaman
# mentee sebagai v1.0.1+<build> sekaligus penghancur cache (query ?v=<build>).
# Hanya dijalankan pada mode 'all', SEBELUM commit, agar ikut ter-commit.
bump_build() {
    local f="test/build_number.txt"
    [ -d test ] || return 0
    local n=0
    if [ -f "$f" ]; then
        n="$(tr -dc '0-9' < "$f")"
    fi
    n=$(( ${n:-0} + 1 ))
    printf '%s' "$n" > "$f"

    # Selaraskan versi cache service worker dengan nomor build. Dengan begitu
    # sw.js berubah tiap push, browser memasang SW baru, dan cache lama dibuang.
    if [ -f sw.js ]; then
        sed -i -E "s/(const CACHE_NAME = 'tracker-amalan-v)[0-9]+(';)/\1${n}\2/" sw.js
    fi

    ok "Build number PWA -> $n (cache sw.js v$n)"
}

do_ftp() {
    need_git_ftp
    step "Commit terakhir di server: $(git ftp show 2>/dev/null | head -1 | cut -d' ' -f2 || echo 'belum ada .git-ftp.log')"

    # .htaccess di server disuntik InfinityFree; push akan menimpanya
    if git diff --cached --name-only 2>/dev/null | grep -qx '.htaccess'; then
        warn ".htaccess ikut berubah. Server punya blok 'InfinityFree Managed Code'"
        warn "yang akan hilang jika Anda push."
        printf "Tetap push .htaccess? [y/N]: "
        read -r j
        [ "$j" = "y" ] || { warn ".htaccess dilewati."; return 0; }
    fi

    local log_before=0
    if [ -f "$LOGFILE" ]; then
        log_before="$(wc -l < "$LOGFILE" | tr -d '[:space:]')"
    fi

    step "Push ke server FTP..."
    run git ftp push
    ok "FTP tersinkron."

    # git-ftp berhenti lebih awal ("Everything up-to-date") bila tidak ada commit
    # baru, sehingga berkas dari .git-ftp-include (admin.json + _manifest/_ai)
    # belum tentu ter-upload.
    local new_log
    new_log="$(tail -n +"$((log_before + 1))" "$LOGFILE" 2>/dev/null || true)"
    ensure_included_uploaded "$new_log"
}

# Pastikan berkas dari .git-ftp-include (kredensial admin, token manifest, dan
# kunci AI) benar-benar ada di server, walau tidak ada commit baru.
ensure_included_uploaded() {
    local new_log="$1"
    local mark=".git-ftp-admin.sha"

    # Kumpulkan seluruh berkas yang selalu harus ada di server.
    local files=()
    local f
    [ -f test/admin.json ] && files+=("test/admin.json")
    local dir
    for dir in test/_manifest test/_ai; do
        [ -d "$dir" ] || continue
        while IFS= read -r -d '' f; do
            files+=("$f")
        done < <(find "$dir" -type f -print0 2>/dev/null)
    done
    [ "${#files[@]}" -gt 0 ] || return 0

    # Sidik jari isi (hash + path) agar perubahan apa pun terdeteksi.
    local want have
    want="$(for f in "${files[@]}"; do
        printf '%s %s\n' "$(sha1sum "$f" | awk '{print $1}')" "$f"
    done | sort | sha1sum | awk '{print $1}')"
    have="$(cat "$mark" 2>/dev/null || true)"
    [ "$want" = "$have" ] && return 0

    if printf '%s' "$new_log" | grep -qi 'up-to-date'; then
        warn "Kredensial/berkas rahasia berubah, tetapi tidak ada commit baru."
        warn "Upload ulang (--all) agar berkas dari .git-ftp-include ikut terkirim..."
        run git ftp push --all
    fi
    printf '%s\n' "$want" > "$mark"
    ok "Kredensial & berkas rahasia terkirim ke server FTP."
}

case "${1:-all}" in
    commit)
        shift
        do_commit "$*"
        ;;
    github)
        do_github
        ;;
    ftp)
        do_ftp
        ;;
    status)
        step "Status branch:"
        git status --short --branch
        echo
        step "Commit terakhir di server FTP:"
        git ftp show 2>/dev/null | head -3 || warn "belum ada .git-ftp.log di server"
        echo
        step "HEAD lokal : $(git rev-parse --short HEAD)"
        step "HEAD server: $(git ftp show 2>/dev/null | head -1 | cut -d' ' -f2 || echo '?')"
        ;;
    all)
        bump_build
        do_commit "${2:-}"
        do_github
        do_ftp
        step "Selesai: GitHub + FTP tersinkron."
        ;;
    *)
        err "Pakai: $0 [all|github|ftp|commit <msg>|status]"
        exit 1
        ;;
esac
