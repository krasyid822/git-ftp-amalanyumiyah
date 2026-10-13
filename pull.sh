#!/usr/bin/env bash
#
# pull.sh - Menarik perubahan terbaru dari server FTP ke repo ini.
#
# Cara pakai:
#   ./pull.sh          -> git ftp pull (merge ke branch sekarang)
#   ./pull.sh init     -> upload semua file & buat .git-ftp.log di server
#   ./pull.sh show     -> lihat commit terakhir yang di-deploy
#   ./pull.sh log      -> riwayat commit sampai commit terakhir yang di-deploy
#
# Kredensial dibaca dari ~/.netrc, konfigurasi URL dari git config.

set -euo pipefail

cd "$(dirname "$0")"

ACTION="${1:-pull}"
if [ "$#" -gt 0 ]; then shift; fi

# Pastikan git-ftp tersedia
if ! command -v git-ftp >/dev/null 2>&1; then
    echo "Error: git-ftp belum terinstall."
    echo "  mkdir -p ~/.local/bin && curl -sSLO https://github.com/git-ftp/git-ftp/archive/refs/heads/master.tar.gz"
    echo "  tar xzf master.tar.gz && cp git-ftp-master/git-ftp ~/.local/bin/ && chmod +x ~/.local/bin/git-ftp"
    exit 1
fi

# Pastikan konfigurasi URL sudah ada
if [ -z "$(git config --local --get git-ftp.url || true)" ]; then
    echo "Error: git-ftp.url belum di-set."
    echo "  git config --local git-ftp.url ftp://ftpupload.net/amalanyaumiyah.xo.je/htdocs/"
    echo "  git config --local git-ftp.user if0_37577629"
    exit 1
fi

# 'pull' di git-ftp butuh lftp
if [ "$ACTION" = "pull" ] && ! command -v lftp >/dev/null 2>&1; then
    echo "Error: git ftp pull membutuhkan lftp."
    echo "  sudo pacman -S lftp"
    exit 1
fi

case "$ACTION" in
    init)  git ftp init   "$@" ;;
    show)  git ftp show   "$@" ;;
    log)   git ftp log    "$@" ;;
    pull)  git ftp pull   "$@" ;;
    *)
        echo "Pakai: $0 [pull|init|show|log]"
        exit 1
        ;;
esac
