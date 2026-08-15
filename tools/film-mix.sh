#!/usr/bin/env bash
# Собирает дорожку роликов заново: картинка как есть, голос из съёмки,
# музыка и перо — из film-audio.py.
#
#     ./tools/film-audio.py  →  tools/build/*.wav
#     ./tools/film-mix.sh    →  assets/video/hero-*.mp4
#
# Прогон идемпотентен: голос берётся не из готового ролика, а из чистой
# дорожки tools/source/voice-*.m4a. Иначе второй запуск подмешал бы
# музыку к музыке, и с каждым разом ролик звучал бы гуще.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD="$ROOT/tools/build"
VIDEO="$ROOT/assets/video"

GM=${GM:-0.26}      # музыка
GP=${GP:-0.68}      # перо
DUCK=${DUCK:-0.32}  # насколько музыка приседает под голосом

# Окна, в которых звучит голос. Сняты не на слух, а silencedetect'ом
# с чистых дорожек:
#   ffmpeg -i tools/source/voice-ru.m4a -af silencedetect=noise=-45dB:d=0.2 -f null -
WIN_ru="0.50 0.91 1.94 2.61 6.02 6.61"
WIN_en="0.59 1.12 2.25 2.88 6.05 6.68"
WIN_es="1.50 1.87 4.10 4.58 7.70 8.34"
WIN_zh="1.00 1.48 3.00 3.62 7.40 8.04"

[ -f "$BUILD/music.wav" ] || { echo "Нет $BUILD/music.wav — сперва python3 tools/film-audio.py"; exit 1; }

# Трапеция приседания: вход за 0.12 с до реплики, выход за 0.30 с после.
ducker () {
  local expr="0" a b
  set -- $1
  while [ $# -ge 2 ]; do
    a=$1; b=$2; shift 2
    expr="max($expr,max(0\,min(1\,min((t-($a-0.12))/0.12\,(($b+0.30)-t)/0.30))))"
  done
  echo "$expr"
}

for LANG in ru en es zh; do
  eval "WIN=\$WIN_$LANG"
  MVOL="$GM*(1-$DUCK*($(ducker "$WIN")))"
  TMP="$VIDEO/.hero-$LANG.new.mp4"

  # level=disabled у alimiter обязателен: по умолчанию он подтягивает
  # результат обратно к 0 dBFS, и ограничение превращается в подъём
  # громкости — на стенде пик выходил -0.4 dBFS вместо запрошенного -1.5.
  ffmpeg -v error -y \
    -i "$VIDEO/hero-$LANG.mp4" \
    -i "$ROOT/tools/source/voice-$LANG.m4a" \
    -i "$BUILD/music.wav" \
    -i "$BUILD/pen-$LANG.wav" \
    -filter_complex "\
      [2:a]highpass=f=130:poles=2,volume=eval=frame:volume='$MVOL'[m]; \
      [3:a]highpass=f=250:poles=2,lowpass=f=7000:poles=2,volume=$GP[p]; \
      [1:a][m][p]amix=inputs=3:duration=first:normalize=0[a]; \
      [a]alimiter=limit=0.84:level=disabled:attack=5:release=80[out]" \
    -map 0:v -map "[out]" -c:v copy -c:a aac -b:a 128k -ar 48000 -ac 2 \
    -movflags +faststart -shortest \
    "$TMP"
  mv "$TMP" "$VIDEO/hero-$LANG.mp4"

  printf 'hero-%s.mp4 ' "$LANG"
  ffmpeg -hide_banner -nostats -i "$VIDEO/hero-$LANG.mp4" -af ebur128=peak=true -f null - 2>&1 \
    | grep -A4 "Integrated loudness" | grep -E "I:|Peak:" | tr -s ' \n' ' '
  echo
done
