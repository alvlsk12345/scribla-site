#!/usr/bin/env bash
# Приводит готовый (записанный или сгенерированный) звук к тому виду,
# в каком его ждёт film-mix.sh. Нужен, чтобы материал со стороны
# вставал в ролик без ручной возни в редакторе.
#
#     ./tools/film-prep.sh music ~/Downloads/track.mp3
#     ./tools/film-prep.sh pen ru ~/Downloads/quill.wav
#     ./tools/film-prep.sh pen en ~/Downloads/quill.wav
#     ./tools/film-mix.sh
#
# Что делает:
#   музыка — 48 кГц стерео, ровно 8,70 с (короткое зацикливается),
#            вход 1,0 с, уход 0,65 с в конце;
#   перо   — то же плюс главное: звук остаётся ТОЛЬКО в интервалах
#            письма, снятых покадрово с ролика. Непрерывный скрип
#            на все девять секунд читается как шум в комнате,
#            а не как пишущее перо.
#
# Громкость подгоняется по RMS к тем же величинам, что были у
# синтезированных дорожек, — тогда GM и GP в film-mix.sh продолжают
# значить то же самое и балансировать заново не нужно.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BUILD="$ROOT/tools/build"
DUR=8.70
mkdir -p "$BUILD"

# Интервалы письма (начало конец), снятые покадрово с самих роликов.
# У ru и en совпадают с теми, что зашиты в film-audio.py.
#
# Снимать их приходится глазами, а не счётом: пробовали считать прирост
# площади чернил по кадрам — приём проваливается, потому что камера
# в ролике отъезжает, надпись от этого мельчает, и убывание съедает
# прирост. На известных интервалах ru проверка это и показала.
#
# В es и zh макро в начале длиннее, перо трогается позже; в zh вдобавок
# заметная пауза перед вопросительным знаком, поэтому интервала три.
STROKES_ru="0.45 2.58 2.68 2.94 3.28 5.46"
STROKES_en="0.40 2.20 2.30 2.56 2.82 5.58"
STROKES_es="1.70 4.05 4.35 8.20"
STROKES_zh="1.20 2.80 3.20 7.05 7.60 8.20"

TARGET_RMS_music=-17.6
TARGET_RMS_pen=-24.5

die () { echo "$*" >&2; exit 1; }

# Подгоняем по RMS, пик придерживаем ограничителем.
fit_level () { # файл целевой_RMS
  local f=$1 target=$2 rms gain
  rms=$(ffmpeg -hide_banner -nostats -i "$f" -af volumedetect -f null - 2>&1 \
        | sed -n 's/.*mean_volume: \(-*[0-9.]*\) dB.*/\1/p')
  [ -n "$rms" ] || die "не измерить громкость: $f"
  gain=$(python3 -c "print(round($target - ($rms), 2))")
  echo "$gain"
}

KIND=${1:-} || true
[ -n "$KIND" ] || die "как звать: film-prep.sh music <файл> | pen ru|en <файл>"

case "$KIND" in
  music)
    SRC=${2:-}; [ -f "$SRC" ] || die "нет файла: $SRC"
    TMP="$BUILD/.music.tmp.wav"
    ffmpeg -v error -y -stream_loop -1 -i "$SRC" -t "$DUR" \
      -af "aformat=sample_fmts=s16:sample_rates=48000:channel_layouts=stereo,\
afade=t=in:st=0:d=1.0,afade=t=out:st=8.05:d=0.65" \
      -ac 2 -ar 48000 "$TMP"
    G=$(fit_level "$TMP" "$TARGET_RMS_music")
    ffmpeg -v error -y -i "$TMP" -af "volume=${G}dB,alimiter=limit=0.9:level=disabled" \
      -ac 2 -ar 48000 "$BUILD/music.wav"
    rm -f "$TMP"
    echo "музыка → tools/build/music.wav (поправка ${G} дБ)"
    ;;

  pen)
    LANG=${2:-}; SRC=${3:-}
    eval "S=\${STROKES_$LANG:-}" 2>/dev/null || true
    [ -n "${S:-}" ] || die "язык ru или en, а не «$LANG»"
    [ -f "$SRC" ] || die "нет файла: $SRC"

    # Ворота: звук живёт только внутри интервалов письма, края
    # сглажены 18 мс — иначе на стыках щёлкает.
    EXPR="0"
    set -- $S
    while [ $# -ge 2 ]; do
      a=$1; b=$2; shift 2
      EXPR="max($EXPR,max(0\,min(1\,min((t-$a)/0.018\,($b-t)/0.018))))"
    done

    TMP="$BUILD/.pen.tmp.wav"
    ffmpeg -v error -y -stream_loop -1 -i "$SRC" -t "$DUR" \
      -af "aformat=sample_fmts=s16:sample_rates=48000:channel_layouts=stereo,\
volume=eval=frame:volume='$EXPR'" \
      -ac 2 -ar 48000 "$TMP"
    G=$(fit_level "$TMP" "$TARGET_RMS_pen")
    ffmpeg -v error -y -i "$TMP" -af "volume=${G}dB,alimiter=limit=0.9:level=disabled" \
      -ac 2 -ar 48000 "$BUILD/pen-$LANG.wav"
    rm -f "$TMP"
    echo "перо ($LANG) → tools/build/pen-$LANG.wav (поправка ${G} дБ)"
    ;;

  *) die "первым словом music или pen, а не «$KIND»" ;;
esac
