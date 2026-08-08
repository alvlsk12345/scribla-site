'use strict';

/* Проверки фильтра брани, версия на JS. Случаи лежат в cases.json —
 * тот же файл читает test/profanity.php, поэтому реализации не могут
 * разойтись незаметно. */

const fs = require('fs');
const path = require('path');
const { isFoul, found } = require('../lib/profanity');

const cases = JSON.parse(fs.readFileSync(path.join(__dirname, 'cases.json'), 'utf8'));
let bad = 0;

for (const s of cases.foul)
  if (!isFoul(s)) { console.log('ПРОПУСТИЛ:', JSON.stringify(s)); bad++; }
for (const s of cases.clean)
  if (isFoul(s)) { console.log('ЛОЖНОЕ:   ', JSON.stringify(s), '→', found(s)); bad++; }

const total = cases.foul.length + cases.clean.length;
if (bad) { console.log(`\n${bad} из ${total} мимо.`); process.exit(1); }
console.log(`JS:  все ${total} проверок сошлись.`);
