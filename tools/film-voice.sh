#!/usr/bin/env bash
# Собирает дорожку голоса из отдельных реплик.
#
#     ./tools/film-voice.sh ru     # → tools/source/voice-ru.m4a
#     ./tools/film-voice.sh en     # → tools/source/voice-en.m4a
#
# Реплики лежат порознь (tools/source/vox/<язык>-1..3.wav) не по лени,
# а потому что между ними в ролике по секунде и больше тишины: сплошную
# запись пришлось бы резать, а нарезка не переживает перегенерацию.
#
# Каждая реплика встаёт туда, где начиналась речь в дорожке со съёмки
# (tools/source/voice-*.filmed.m4a) — картинка смонтирована под неё,
# и сдвиг разъедет голос с письмом на экране.
#
# Начало звука внутри файла ищется само: генератор оставляет спереди
# ~35 мс тишины, и без поправки речь уезжает на эту величину.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/tools/source"
DUR=8.64

# Начала реплик, снятые с дорожек со съёмки порогом -55 дБ.
STARTS_ru="0.503 1.943 6.015"
STARTS_en="0.594 2.250 6.048"

# Третья реплика (название под логотипом) звучит тише первых двух —
# столько же, сколько было в оригинале.
QUIET_ru=-11.6
QUIET_en=-12.7

# Громкость всей дорожки: ровно та, что была у съёмки, иначе
# в film-mix.sh поедет весь баланс.
TARGET_LUFS_ru=-17.8
TARGET_LUFS_en=-16.0

die () { echo "$*" >&2; exit 1; }

LANG=${1:-}
eval "STARTS=\${STARTS_$LANG:-}" 2>/dev/null || true
[ -n "${STARTS:-}" ] || die "язык ru или en, а не «$LANG»"
eval "QUIET=\$QUIET_$LANG; TARGET=\$TARGET_LUFS_$LANG"

# Где внутри файла начинается звук. Вывод ffmpeg забирается целиком
# в переменную, а не режется трубой: `| head -1` закрывает трубу
# на первой строке, и ffmpeg зависает на записи в неё.
onset () {
  local out
  out=$(ffmpeg -hide_banner -nostats -i "$1" -af "silencedetect=noise=-50dB:d=0.03" -f null - 2>&1)
  printf '%s\n' "$out" | sed -n 's/.*silence_end: \([0-9.]*\).*/\1/p' | sed -n 1p
}

FMT="aformat=sample_fmts=fltp:sample_rates=48000:channel_layouts=stereo"
INPUTS=(); FILTER=""; MIX=""; i=0
for START in $STARTS; do
  i=$((i + 1))
  F="$SRC/vox/$LANG-$i.wav"
  [ -f "$F" ] || die "нет реплики: $F"
  O=$(onset "$F"); [ -n "$O" ] || O=0
  MS=$(python3 -c "print(max(0, round(($START - $O) * 1000)))")
  GAIN=""; [ "$i" = 3 ] && GAIN="volume=${QUIET}dB,"
  INPUTS+=(-i "$F")
  FILTER="$FILTER[$((i-1)):a]$FMT,${GAIN}adelay=$MS|$MS[a$i];"
  MIX="$MIX[a$i]"
done

TMP="$SRC/.voice-$LANG.tmp.wav"
ffmpeg -v error -y "${INPUTS[@]}" -filter_complex \
  "${FILTER}${MIX}amix=inputs=$i:duration=longest:normalize=0,apad,atrim=0:$DUR[o]" \
  -map "[o]" -ac 2 -ar 48000 "$TMP"

# Подгонка громкости — по EBU R128, а не по RMS: речь с паузами
# средним не мерится (см. предупреждение про перо в README).
NOW=$(ffmpeg -hide_banner -nostats -i "$TMP" -af ebur128 -f null - 2>&1 \
      | grep -A3 "Integrated loudness" | sed -n 's/.*I: *\([-0-9.]*\) LUFS.*/\1/p')
[ -n "$NOW" ] || die "не измерить громкость: $TMP"
G=$(python3 -c "print(round($TARGET - ($NOW), 2))")

ffmpeg -v error -y -i "$TMP" -af "volume=${G}dB" \
  -c:a aac -b:a 128k -ar 48000 -ac 2 "$SRC/voice-$LANG.m4a"
rm -f "$TMP"

echo "голос ($LANG) → tools/source/voice-$LANG.m4a (поправка ${G} дБ)"
