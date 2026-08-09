#!/usr/bin/env bash
# Сайт локально — на том же PHP, что и на хостинге.
#
#     ./dev.sh            → http://127.0.0.1:4173
#
# Данные форм ложатся в .local-data и в репозиторий не попадают.
# Почта отсюда не уходит, пока в .local-data/mail.json нет настроек;
# это ровно то же правило, что и на сервере.

set -euo pipefail
cd "$(dirname "$0")"

export SCRIBLA_DATA="${SCRIBLA_DATA:-$PWD/.local-data}"
mkdir -p "$SCRIBLA_DATA"

exec php -S "127.0.0.1:${PORT:-4173}" -t . test/router.php
