#!/usr/bin/env bash
# Cek otomatis Bagian C6 (anti AI-slop). Keluar dengan kode 1 jika ada temuan.
# Folder scripts/ tidak ikut dipindai karena berisi pola pencarian itu sendiri.
set -uo pipefail

cd "$(dirname "$0")/.." || exit 2
export LC_ALL=C.UTF-8

ada_path() {
    local path
    for path in "$@"; do
        [[ -e "$path" ]] && printf '%s\n' "$path"
    done
}

mapfile -t SUMBER < <(ada_path app bootstrap/app.php config database lang resources routes tests .env.example)
mapfile -t SUMBER_TANPA_TEST < <(ada_path app bootstrap/app.php config database lang resources routes .env.example)
mapfile -t KONTEN < <(ada_path app database lang resources routes)
mapfile -t DOKUMEN < <(ada_path README.md dokumentasi.md CLAUDE.md)

EMOJI='[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{1F1E6}-\x{1F1FF}\x{FE0F}]'
temuan=0

periksa() {
    local judul="$1" pola="$2"
    shift 2
    local hasil
    hasil=$(grep -rnIP --exclude-dir=vendor --exclude-dir=node_modules -e "$pola" "$@" 2>/dev/null)
    if [[ -n "$hasil" ]]; then
        printf '\n[%s]\n%s\n' "$judul" "$hasil"
        temuan=1
    fi
}

periksa 'Emoji di source' "$EMOJI" "${SUMBER[@]}"
periksa 'Emoji di dokumentasi' "$EMOJI" "${DOKUMEN[@]}"
periksa 'Sisa debug' 'console\.log|\bdebugger\b|\bdd\(|\bdump\(|\bvar_dump\(|\bray\(' "${SUMBER[@]}"
periksa 'Placeholder' 'TODO|FIXME|(?i:lorem|ipsum|john doe)' "${SUMBER[@]}"
periksa 'Domain contoh (di luar test)' 'example\.(com|org|net)' "${SUMBER_TANPA_TEST[@]}"
periksa 'Pembungkam checker' '@ts-ignore|@ts-expect-error|eslint-disable|@phpstan-ignore|\bas any\b|:\s*any\b' "${SUMBER[@]}"
periksa 'Kata terlarang C3' '(?i:seamless|revolusioner|solusi (terdepan|terbaik)|era digital|transformasi digital|memberdayakan|tingkatkan pengalaman|all-in-one|mudah, cepat, dan aman|canggih|inovatif|selamat datang di masa depan|mari bersama)|#1\b' "${KONTEN[@]}"

if git rev-parse --git-dir > /dev/null 2>&1; then
    hasil=$(git log --format='%h %s' | grep -P "$EMOJI")
    if [[ -n "$hasil" ]]; then
        printf '\n[Emoji di pesan commit]\n%s\n' "$hasil"
        temuan=1
    fi
fi

if [[ $temuan -eq 0 ]]; then
    echo 'check:slop: tidak ada temuan.'
fi

exit $temuan
