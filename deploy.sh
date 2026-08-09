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
OUT=$(curl -sS --max-time 120 -X POST "$SITE/api/deploy.php?key=$KEY&ref=$REF")
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
