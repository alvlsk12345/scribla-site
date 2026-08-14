#!/usr/bin/env bash
# Выкладка сайта: коммит → GitHub → сервер забирает сам.
#
# Ключ лежит в ~/.scribla-deploy-key и в репозиторий не попадает.
# Положить его туда один раз:
#     echo 'ВАШ_КЛЮЧ' > ~/.scribla-deploy-key && chmod 600 ~/.scribla-deploy-key
# Это тот же ключ, что в scribla-data/admin.key на сервере.

set -euo pipefail

SITE=${SITE:-https://scribla.io}
KEY_FILE=${SCRIBLA_KEY_FILE:-$HOME/.scribla-deploy-key}
cd "$(dirname "$0")"

[ -f "$KEY_FILE" ] || { echo "Нет файла с ключом: $KEY_FILE"; exit 1; }
KEY=$(tr -d '[:space:]' < "$KEY_FILE")
[ -n "$KEY" ] || { echo "Файл с ключом пуст: $KEY_FILE"; exit 1; }

# 1. Проверки. Выкладывать сломанный фильтр незачем.
echo "→ стенд"
node test/run.js
if command -v php >/dev/null; then
  php test/profanity.php
  php test/mail.php
  php test/log.php
  php test/report.php
  php test/ask.php
fi

# 2. Отправка в GitHub — сервер берёт файлы оттуда, так что без этого
#    шага выкладка молча развернёт прежнюю версию.
if ! git diff --quiet || ! git diff --cached --quiet; then
  echo "→ незакоммиченные правки:"; git status --short
  echo "Закоммитьте их — сервер забирает только то, что в GitHub."; exit 1
fi
echo "→ push"
git push origin main

# 3. Сервер забирает сам — именно этот коммит, а не «ветку main».
#    Архив ветки GitHub отдаёт из кэша CDN, и две выкладки подряд уже
#    приводили к тому, что вторая привозила срез до последнего коммита,
#    отчитываясь при этом об успехе. Хэш коммита такого не допускает.
echo "→ выкладка"
REF=$(git rev-parse HEAD)
# Обращаемся к .php напрямую, а не к чистому адресу: так выкладка
# не зависит от того, доехал ли уже свежий .htaccess.
# Настройки почты едут вместе с выкладкой, если файл на месте. Пароль
# от ящика — единственный секрет, который нельзя ни завести на сервере
# самому, ни положить в репозиторий; телом запроса он не попадает
# ни в историю команд, ни в журналы прокси. Нет файла — сервер оставляет
# свои настройки как есть.
#
# Тем же путём едут два ключа ручки AI: настоящий ключ Ollama и наш
# собственный, которым приложение подписывает обращения. Ни один из них
# не может лежать в публичном репозитории, а SSH на этом хостинге нет
# вовсе — остаётся тело запроса.
MAIL_FILE=${SCRIBLA_MAIL_FILE:-$HOME/.scribla-mail.json}
AI_KEY_FILE=${SCRIBLA_AI_KEY_FILE:-$HOME/.scribla-ai-app.key}
OLLAMA_KEY_FILE=${SCRIBLA_OLLAMA_KEY_FILE:-$HOME/.scribla-ollama.key}

# Через файл, а не через --data: тело в аргументах командной строки
# видно всей машине в `ps`.
BODY=""
PARTS=""
if [ -f "$MAIL_FILE" ]; then
  PARTS="\"mail\":$(cat "$MAIL_FILE")"
  echo "  почта: настройки из $MAIL_FILE едут вместе с выкладкой"
fi

KEYS=""
if [ -f "$AI_KEY_FILE" ]; then
  KEYS="\"ai\":\"$(tr -d '[:space:]' < "$AI_KEY_FILE")\""
fi
if [ -f "$OLLAMA_KEY_FILE" ]; then
  [ -n "$KEYS" ] && KEYS="$KEYS,"
  KEYS="$KEYS\"ollama\":\"$(tr -d '[:space:]' < "$OLLAMA_KEY_FILE")\""
fi
if [ -n "$KEYS" ]; then
  [ -n "$PARTS" ] && PARTS="$PARTS,"
  PARTS="$PARTS\"keys\":{$KEYS}"
  echo "  ключи AI: едут вместе с выкладкой"
fi

# Рабочие тексты инструкции для модели. В репозитории лежат запасные,
# короткие и общие; эти выкуплены живыми провалами и в публичный
# репозиторий не поедут.
PROMPT_FILE=${SCRIBLA_PROMPT_FILE:-$HOME/.scribla-prompt.json}
if [ -f "$PROMPT_FILE" ]; then
  if ! python3 -c "import json,sys; d=json.load(open(sys.argv[1])); sys.exit(0 if int(d.get('version',0))>0 else 1)" "$PROMPT_FILE"; then
    echo "В $PROMPT_FILE нет поля version — выкладка остановлена."; exit 1
  fi
  [ -n "$PARTS" ] && PARTS="$PARTS,"
  PARTS="$PARTS\"prompt\":$(cat "$PROMPT_FILE")"
  echo "  инструкция модели: тексты из $PROMPT_FILE едут вместе с выкладкой"
fi

if [ -n "$PARTS" ]; then
  BODY=$(umask 077; mktemp)
  printf '{%s}' "$PARTS" > "$BODY"
fi

if [ -n "$BODY" ]; then
  OUT=$(curl -sS --max-time 120 -X POST "$SITE/api/deploy.php?key=$KEY&ref=$REF" \
        -H 'Content-Type: application/json' --data-binary "@$BODY")
  rm -f "$BODY"
else
  OUT=$(curl -sS --max-time 120 -X POST "$SITE/api/deploy.php?key=$KEY&ref=$REF")
fi
echo "$OUT" | python3 -m json.tool 2>/dev/null || echo "$OUT"
echo "$OUT" | grep -q '"message":"Выложено"' || { echo "Выкладка не удалась."; exit 1; }

if echo "$OUT" | grep -q "\"ref\":\"$REF\""; then
  echo "  развёрнут коммит ${REF:0:7}"
elif echo "$OUT" | grep -q '"ref"'; then
  echo "Сервер развернул не тот коммит, который мы отправили."; exit 1
else
  echo "  внимание: на сервере ещё старый обработчик выкладки —"
  echo "  он берёт ветку, а не коммит. Следующий запуск это исправит."
fi

# 4. Проверяем снаружи, а не верим ответу.
echo "→ проверка"
CODE=$(curl -sS -o /dev/null -w '%{http_code}' "$SITE/")
SIZE=$(curl -sS -o /dev/null -w '%{size_download}' "$SITE/")
echo "  главная: $CODE, $SIZE байт"
[ "$CODE" = "200" ] || { echo "Сайт отвечает не 200."; exit 1; }
echo "Готово."
