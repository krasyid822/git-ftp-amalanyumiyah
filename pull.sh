#!/usr/bin/env bash
#
# pull.sh - Menarik perubahan terbaru dari server FTP ke repo ini.
#
# Cara pakai:
#   ./pull.sh              -> git ftp pull --changed-only --no-commit
#                             + ambil folder/file BARU dari server (lihat bawah)
#   ./pull.sh new          -> hanya ambil folder/file baru dari server
#   ./pull.sh new --deep   -> periksa SELURUH pohon server (lambat, ~3-5 menit)
#   ./pull.sh new --with-data
#                          -> sekalian tarik data_amalan.json (lihat bawah)
#   ./pull.sh init         -> upload semua file & buat .git-ftp.log di server
#   ./pull.sh show         -> lihat commit terakhir yang di-deploy
#   ./pull.sh log          -> riwayat commit sampai commit terakhir di-deploy
#   ./pull.sh status       -> cek status tanpa mengubah apa pun
#   ./pull.sh tail         -> pantau log proses yang sedang jalan
#   ./pull.sh full         -> mirror SELURUH server (berbahaya, baca header)
#
# KEAMANAN: pull memakai --changed-only + --no-commit supaya perubahan
# lokal Anda tidak dihapus oleh 'mirror --delete' dan tidak ter-commit
# tanpa izin. Baca pull.sh sebelum memakai aksi 'full'.
#
# CATATAN PENTING soal folder/file yang dibuat LANGSUNG di server:
#   'git ftp pull --changed-only' hanya mengunduh file yang muncul di
#   'git diff <commit terakhir di-deploy>'. Jadi grup/mentee yang dibuat
#   lewat panel admin di server TIDAK ikut terunduh (tidak ada di commit).
#   Karena itu ./pull.sh (dan ./pull.sh new) menjalankan pemindaian tambahan
#   yang mengambil apa pun yang belum ada di lokal.
#   - Pemindaian default memeriksa 2 tingkat (root + tiap folder tingkat 1),
#     cukup untuk grup baru maupun mentee baru, dan relatif cepat.
#   - Pakai --deep bila ingin memeriksa sampai ke dalam tiap folder.
#   - Aman: memakai --only-missing dan tidak pernah --delete, jadi file
#     lokal yang sudah ada TIDAK ditimpa/dihapus.
#   - data_amalan.json & hijri_cache.json sengaja tidak ditarik (mengikuti
#     .git-ftp-ignore). Tambahkan --with-data bila ingin ikut ditarik
#     (aman, karena hanya file yang belum ada yang diunduh).
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

# ===========================================================================
#  Ambil folder/file BARU dari server
# ===========================================================================
FTP_USER=""; FTP_PASS=""; FTP_PROTO=""; FTP_HOST=""; FTP_PATH=""
EXCLUDES=""; EXCLUDE_PATTERNS=()
WITH_DATA=0; DEEP=0

# Baca kredensial & pecah git-ftp.url menjadi proto/host/path.
load_ftp_cfg() {
    local url rest pwcmd
    url="$(git config --local --get git-ftp.url || true)"
    [ -n "$url" ] || { err "git-ftp.url belum di-set."; exit 1; }
    FTP_PROTO="${url%%://*}"
    rest="${url#*://}"
    FTP_HOST="${rest%%/*}"
    FTP_PATH="/${rest#*/}"
    [ "$FTP_PATH" != "/" ] || FTP_PATH="/"
    FTP_USER="$(git config --local --get git-ftp.user || true)"
    [ -n "$FTP_USER" ] || { err "git-ftp.user belum di-set."; exit 1; }
    pwcmd="$(git config --local --get git-ftp.password-command || true)"
    [ -n "$pwcmd" ] || { err "git-ftp.password-command belum di-set."; exit 1; }
    FTP_PASS="$(eval "$pwcmd" || true)"
    [ -n "$FTP_PASS" ] || { err "Gagal membaca kredensial FTP (lihat ~/.netrc)."; exit 1; }
}

# Bangun daftar pengecualian dari .git-ftp-ignore (+ tambahan lokal).
# Dipakai dua kali: sebagai glob lftp (EXCLUDES) dan sebagai pola bash
# (EXCLUDE_PATTERNS) supaya berkas tunggal juga bisa disaring.
build_excludes() {
    EXCLUDES=""
    EXCLUDE_PATTERNS=()
    local line
    if [ -f '.git-ftp-ignore' ]; then
        while IFS= read -r line || [ -n "$line" ]; do
            case "$line" in ''|\#*) continue ;; esac
            if [ "$WITH_DATA" -eq 1 ]; then
                # --with-data: jangan kecualikan berkas data.
                case "$line" in
                    data_amalan.json|hijri_cache.json) continue ;;
                    \*data_amalan.json|\*hijri_cache.json) continue ;;
                esac
            fi
            EXCLUDES="$EXCLUDES --exclude-glob $line"
            EXCLUDE_PATTERNS+=("$line")
        done < .git-ftp-ignore
    fi
    local extra
    for extra in '.git/*' '.git-ftp.log' '.git-ftp-ignore' '.git-ftp-include' \
                 '.git-ftp-pull.log' '.git-ftp-push.log' '.git-ftp-admin.sha' \
                 'test/admin.json' 'test/_manifest' 'test/_manifest/*'; do
        EXCLUDES="$EXCLUDES --exclude-glob $extra"
        EXCLUDE_PATTERNS+=("$extra")
    done
}

# Jalankan skrip lftp ke root server. stdout = data, stderr = pesan error.
run_lftp() {
    lftp -u "$FTP_USER,$FTP_PASS" "$FTP_PROTO://$FTP_HOST$FTP_PATH" \
        -e "set ssl:verify-certificate no; $1; bye"
}

# Cocokkan jalur dengan daftar pola .git-ftp-ignore.
is_excluded() {
    local pat
    for pat in ${EXCLUDE_PATTERNS[@]+"${EXCLUDE_PATTERNS[@]}"}; do
        # shellcheck disable=SC2254
        case "$1" in $pat) return 0 ;; esac
    done
    return 1
}

# Folder tingkat-1 yang ada di lokal (kecuali .git).
local_top_dirs() {
    find . -mindepth 1 -maxdepth 1 -type d -not -name '.git' -printf '%f\n' | LC_ALL=C sort
}

# Mode default: pindai 2 tingkat (root + tiap folder tingkat 1) lalu unduh
# yang belum ada di lokal. Cukup cepat, dan menangkap grup/mentee baru.
fetch_new_shallow() {
    local script="" line p isdir
    local out=""

    # Skrip pemindaian: daftar root, lalu daftar isi tiap folder tingkat-1.
    script+='echo __ROOT__; cls -1;'
    while IFS= read -r line; do
        [ -n "$line" ] || continue
        script+=" echo __DIR__ $line; cls -1 \"$line/\";"
    done < <(local_top_dirs)

    info "Memindai folder di server (2 tingkat)..."
    out="$(run_lftp "$script" || true)"

    # --- bandingkan dengan lokal ---
    local -a want_dir=() want_file=()
    while IFS= read -r line; do
        case "$line" in
            ''|__ROOT__|__DIR__\ *) continue ;;
        esac
        isdir=0
        case "$line" in */) isdir=1 ;; esac
        p="${line%/}"
        if [ -e "$p" ]; then continue; fi
        if is_excluded "$p"; then continue; fi
        if [ "$isdir" -eq 1 ]; then want_dir+=("$p"); else want_file+=("$p"); fi
    done <<< "$out"

    if [ "${#want_dir[@]}" -eq 0 ] && [ "${#want_file[@]}" -eq 0 ]; then
        ok "Tidak ada folder/file baru di server (tingkat 1-2)."
        return 0
    fi

    # --- susun skrip unduh ---
    # Folder baru -> mirror seluruh isinya (tanpa delete, hanya yang belum ada).
    # File baru   -> get. Keduanya --only-missing, jadi lokal tidak ditimpa.
    local dl=""
    for p in "${want_dir[@]}"; do
        dl+="echo + folder $p; mirror --only-missing $EXCLUDES \"$p\" \"./$p\";"$'\n'
    done
    for p in "${want_file[@]}"; do
        dl+="echo + file   $p; get -c -O \"$(dirname "$p")\" \"$p\";"$'\n'
    done

    info "Mengunduh: ${#want_dir[@]} folder baru, ${#want_file[@]} berkas baru..."
    echo
    if run_lftp "$dl"; then
        # Hitung berkas yang benar-benar bertambah: seluruh isi folder baru
        # (folder itu tadinya tidak ada) + berkas lepas yang baru diunduh.
        local added=0
        for p in ${want_dir[@]+"${want_dir[@]}"}; do
            added=$((added + $(find "$p" -type f 2>/dev/null | wc -l)))
        done
        added=$((added + ${#want_file[@]}))
        ok "Selesai. Folder baru: ${#want_dir[@]}. Berkas baru: $added."
        warn "Berkas baru belum di-commit. Periksa: git status --short"
    else
        err "Sebagian unduhan gagal (lihat pesan di atas)."
        return 1
    fi
}

# Mode --deep: mirror SELURUH pohon server (hanya yang belum ada di lokal).
# Lambat karena server FTP lambat menjawab LIST (tiap folder ~4 detik).
fetch_new_deep() {
    info "Mode --deep: memindai SELURUH pohon server."
    warn "Ini bisa makan 3-5 menit (server FTP lambat). Mohon tunggu."
    if run_lftp "mirror --only-missing $EXCLUDES . ."; then
        ok "Pemeriksaan penuh selesai."
        warn "Berkas baru belum di-commit. Periksa: git status --short"
    else
        err "Pemeriksaan penuh gagal."
        return 1
    fi
}

# Selidiki opsi --deep/--with-data pada argumen.
ARGS=()
for _a in "$@"; do
    case "$_a" in
        --deep)      DEEP=1 ;;
        --with-data) WITH_DATA=1 ;;
        *)           ARGS+=("$_a") ;;
    esac
done
if [ "${#ARGS[@]}" -gt 0 ]; then set -- "${ARGS[@]}"; else set --; fi

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
        info "Mode aman: --changed-only --no-commit"
        info "Hanya file yang berubah sejak deploy terakhir yang diunduh."
        info "Perubahan lokal Anda tidak akan dihapus."
        info "Pantau dengan: ./pull.sh tail"
        echo
        PULL_EXIT=0; FETCH_EXIT=0
        run git ftp pull --changed-only --no-commit "$@" || PULL_EXIT=$?
        echo
        load_ftp_cfg
        build_excludes
        if [ "$DEEP" -eq 1 ]; then
            fetch_new_deep || FETCH_EXIT=$?
        else
            fetch_new_shallow || FETCH_EXIT=$?
        fi
        # Tujuan utama pull adalah mengambil berkas dari server. Kegagalan
        # 'git ftp pull' (mis. repo masih kotor) tidak menggugurkan hasil
        # pemindaian folder baru di atas.
        EXIT=$FETCH_EXIT
        if [ "$PULL_EXIT" -ne 0 ] && [ "$FETCH_EXIT" -eq 0 ]; then
            warn "git ftp pull gagal (exit $PULL_EXIT), tetapi pengambilan "
            warn "folder/file baru dari server BERHASIL."
        fi
        ;;
    new)
        load_ftp_cfg
        build_excludes
        if [ "$DEEP" -eq 1 ]; then
            fetch_new_deep || EXIT=$?
        else
            fetch_new_shallow || EXIT=$?
        fi
        ;;
    full)
        warn "Mode PENUH: memirror SELURUH isi server dengan --delete."
        warn "File lokal yang tidak ada di server AKAN dihapus."
        warn "SBackup dulu: git status && git log --oneline -1"
        echo
        printf "Ketik 'ya' untuk lanjut: "
        read -r jawab
        if [ "$jawab" = "ya" ]; then
            run git ftp pull "$@" || EXIT=$?
        else
            warn "Dibatalkan."
            EXIT=0
        fi
        ;;
    *)
        err "Pakai: $0 [pull|new|init|show|log|status|tail] [--deep] [--with-data]"
        err "  pull            -> sinkron commit + ambil folder/file baru dari server"
        err "  new             -> hanya ambil folder/file baru dari server"
        err "  new --deep      -> periksa seluruh pohon server (lambat)"
        err "  new --with-data -> sekalian tarik data_amalan.json"
        exit 1
        ;;
esac

DURASI=$(( $(date +%s) - START ))

echo
if [ "$EXIT" -eq 0 ]; then
    ok "Selesai dalam ${DURASI} detik."
    if [ "$ACTION" = "pull" ] || [ "$ACTION" = "new" ]; then
        if [ -e .git/MERGE_HEAD ]; then
            CONFLICT=$(git diff --name-only --diff-filter=U)
            if [ -n "$CONFLICT" ]; then
                warn "Ada konflik merge:"
                echo "$CONFLICT" | while read -r f; do warn "    $f"; done
                warn "Perbaiki, lalu: git add <file> && git commit"
            else
                ok "Merge selesai tanpa konflik."
            fi
        else
            ok "HEAD sekarang: $(git rev-parse --short HEAD)"
        fi
        if ! git diff --quiet || ! git diff --cached --quiet; then
            warn "Perubahan belum di-commit (ini disengaja)."
            warn "Periksa: git status --short"
            warn "Simpan : git add -A && git commit -m 'sinkron dari server'"
            [ -e .git/MERGE_HEAD ] && warn "Buang  : git merge --abort"
        fi
    fi
else
    err "Gagal (exit $EXIT) setelah ${DURASI} detik."
    err "Cek status repo: git status --short --branch"
    err "Kalau detach:    git checkout main"
fi
exit "$EXIT"
