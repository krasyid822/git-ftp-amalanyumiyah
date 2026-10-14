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
# Kredensial dibaca dari ~/.netrc, konfigurasi URL dari git config.

set -euo pipefail

cd "$(dirname "$0")"

RED=$'\033[31m'; GREEN=$'\033[32m'; YELLOW=$'\033[33m'; NC=$'\033[0m'

step() { echo -e "${GREEN}==>${NC} $*"; }
warn() { echo -e "${YELLOW}!  ${NC} $*"; }
err()  { echo -e "${RED}x  ${NC} $*" >&2; }

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
    git add -A
    git commit -m "$msg"
    step "Commit: $msg"
}

do_github() {
    step "Push ke GitHub (origin/main)..."
    git push origin main
    step "GitHub OK."
}

do_ftp() {
    need_git_ftp
    step "Cek commit terakhir yang ter-deploy..."
    git ftp show --quiet 2>/dev/null | head -1 || warn "Belum pernah ada deploy."
    step "Push ke server FTP..."
    git ftp push
    step "FTP OK."
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
        ;;
    all)
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
