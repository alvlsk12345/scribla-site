'use strict';

/* Сервер сайта Scribla.
 *
 * Без зависимостей и без npm install: только встроенный http. Причина
 * не в аскезе — у пакетов свой срок жизни, а этот сервер должен
 * пережить годы без присмотра. Три ручки и раздача статики столько
 * кода не стоят, чтобы тянуть за собой дерево из сотни модулей.
 *
 * Запуск: node server.js   (порт из PORT, по умолчанию 3000)
 */

const http = require('http');
const fs   = require('fs');
const path = require('path');
const { isFoul } = require('./lib/profanity');

const ROOT = __dirname;
const DATA = path.join(ROOT, 'data');
const PORT = Number(process.env.PORT) || 3000;

/* Ключ для чтения собранного. Пустой — значит смотреть нельзя вовсе:
 * лучше не отдать владельцу, чем отдать первому встречному. */
const ADMIN_KEY = process.env.ADMIN_KEY || '';

/* Файлы, которых в вебе быть не должно. Сайт лежит в корне репозитория
 * вместе с сервером — иначе сломался бы GitHub Pages, который пока
 * работает запасным адресом. */
const HIDDEN = new Set(['server.js', 'package.json', 'package-lock.json', 'readme.md']);
const HIDDEN_DIRS = ['lib', 'data', '.git', '.github', '.claude', 'node_modules'];

const TYPES = {
  '.html': 'text/html; charset=utf-8',
  '.css':  'text/css; charset=utf-8',
  '.js':   'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg':  'image/svg+xml',
  '.png':  'image/png',
  '.jpg':  'image/jpeg',
  '.webp': 'image/webp',
  '.ico':  'image/x-icon',
  '.woff2':'font/woff2',
  '.txt':  'text/plain; charset=utf-8',
  '.xml':  'application/xml; charset=utf-8',
};

fs.mkdirSync(DATA, { recursive: true });

// ---------------------------------------------------------------- утилиты

function send(res, code, body, headers = {}) {
  res.writeHead(code, {
    'x-content-type-options': 'nosniff',
    'referrer-policy': 'strict-origin-when-cross-origin',
    ...headers,
  });
  res.end(body);
}

const json = (res, code, obj) =>
  send(res, code, JSON.stringify(obj), { 'content-type': 'application/json; charset=utf-8' });

/** Тело запроса с потолком: без него любой желающий забьёт нам память. */
function readBody(req, limit = 16 * 1024) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on('data', c => {
      size += c.length;
      if (size > limit) { reject(new Error('too-large')); req.destroy(); return; }
      chunks.push(c);
    });
    req.on('end', () => resolve(Buffer.concat(chunks).toString('utf8')));
    req.on('error', reject);
  });
}

/** Дописываем строкой в JSONL. Формат выбран ради живучести: файл
 *  переживает обрыв записи — потеряется последняя строка, а не всё. */
function append(file, obj) {
  fs.appendFileSync(path.join(DATA, file), JSON.stringify(obj) + '\n', 'utf8');
}

/* Грубый лимит по адресу: словарь в памяти, без внешнего хранилища.
 * Переживать перезапуск ему не нужно — он от роботов, а не от осады. */
const hits = new Map();
function tooOften(ip, max = 5, windowMs = 10 * 60_000) {
  const now = Date.now();
  const list = (hits.get(ip) || []).filter(t => now - t < windowMs);
  list.push(now);
  hits.set(ip, list);
  if (hits.size > 5000) hits.clear();
  return list.length > max;
}

const clientIP = req =>
  (req.headers['x-forwarded-for'] || '').split(',')[0].trim()
  || req.socket.remoteAddress || '?';

/* Проверка адреса намеренно нестрогая. Полная по RFC пропускает мусор
 * и отбивает живые адреса; здесь достаточно поймать опечатку. */
const looksLikeEmail = s =>
  typeof s === 'string' && s.length <= 254 && /^[^@\s]+@[^@\s.]+\.[^@\s]{2,}$/.test(s);

// ---------------------------------------------------------------- ручки

async function apiNotify(req, res) {
  const ip = clientIP(req);
  if (tooOften(ip)) return json(res, 429, { error: 'Слишком часто. Попробуйте попозже.' });

  let body;
  try { body = JSON.parse(await readBody(req)); }
  catch { return json(res, 400, { error: 'Не разобрали запрос' }); }

  const email = String(body.email || '').trim().toLowerCase();
  if (!looksLikeEmail(email))
    return json(res, 400, { error: 'Проверьте адрес — похоже, в нём опечатка' });

  append('notify.jsonl', { email, at: new Date().toISOString(), ip });
  json(res, 200, { message: 'Записали. Напишем один раз — когда выйдет.' });
}

async function apiFeedback(req, res) {
  const ip = clientIP(req);
  if (tooOften(ip)) return json(res, 429, { error: 'Слишком часто. Попробуйте попозже.' });

  let body;
  try { body = JSON.parse(await readBody(req, 64 * 1024)); }
  catch { return json(res, 400, { error: 'Не разобрали запрос' }); }

  const message = String(body.message || '').trim();
  const email = String(body.email || '').trim().toLowerCase();

  if (message.length < 10)
    return json(res, 400, { error: 'Слишком коротко — из двух слов не понять, что случилось' });
  if (message.length > 2000)
    return json(res, 400, { error: 'Длиннее двух тысяч знаков не влезет' });
  if (email && !looksLikeEmail(email))
    return json(res, 400, { error: 'Проверьте адрес — похоже, в нём опечатка' });

  /* Мат не отбиваем в лицо. Человек, которого обозвали роботом, второй
   * раз не напишет — а среди грубых писем попадаются самые полезные.
   * Поэтому письмо принимаем и помечаем, разбирает владелец. */
  const foul = isFoul(message);
  append('feedback.jsonl', { message, email, foul, at: new Date().toISOString(), ip });

  json(res, 200, {
    message: foul
      ? 'Отправлено. Это письмо посмотрят руками — так бывает.'
      : 'Спасибо. Прочитаем всё.',
  });
}

/** Чтение собранного. Без ключа в окружении — закрыто наглухо. */
function apiAdmin(req, res, url) {
  if (!ADMIN_KEY) return json(res, 404, { error: 'Не найдено' });
  if (url.searchParams.get('key') !== ADMIN_KEY) return json(res, 403, { error: 'Нет' });

  const read = f => {
    const p = path.join(DATA, f);
    if (!fs.existsSync(p)) return [];
    return fs.readFileSync(p, 'utf8').split('\n').filter(Boolean)
      .map(l => { try { return JSON.parse(l); } catch { return null; } }).filter(Boolean);
  };
  json(res, 200, { notify: read('notify.jsonl'), feedback: read('feedback.jsonl') });
}

// ---------------------------------------------------------------- статика

function serveStatic(res, pathname) {
  let rel = decodeURIComponent(pathname).replace(/^\/+/, '');
  if (rel === '' || rel.endsWith('/')) rel += 'index.html';

  const full = path.resolve(ROOT, rel);

  // Выход за корень, точечные файлы и служебные каталоги — мимо.
  const first = rel.split('/')[0].toLowerCase();
  if (!full.startsWith(ROOT + path.sep)
      || rel.split('/').some(s => s.startsWith('.'))
      || HIDDEN.has(rel.toLowerCase())
      || HIDDEN_DIRS.includes(first)) {
    return send(res, 404, 'Не найдено', { 'content-type': 'text/plain; charset=utf-8' });
  }

  fs.readFile(full, (err, data) => {
    if (err) return send(res, 404, 'Не найдено', { 'content-type': 'text/plain; charset=utf-8' });
    const ext = path.extname(full).toLowerCase();
    /* Шрифты и картинки именованы навсегда, их можно кэшировать надолго;
     * html — нет, иначе правка текста доедет до людей через год. */
    const immutable = ['.woff2', '.png', '.jpg', '.webp', '.svg', '.ico'].includes(ext);
    send(res, 200, data, {
      'content-type': TYPES[ext] || 'application/octet-stream',
      'cache-control': immutable ? 'public, max-age=31536000, immutable' : 'no-cache',
    });
  });
}

// ---------------------------------------------------------------- сервер

http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const p = url.pathname;

  try {
    if (p.startsWith('/api/')) {
      if (req.method === 'POST' && p === '/api/notify')   return await apiNotify(req, res);
      if (req.method === 'POST' && p === '/api/feedback') return await apiFeedback(req, res);
      if (req.method === 'GET'  && p === '/api/admin')    return apiAdmin(req, res, url);
      return json(res, 404, { error: 'Не найдено' });
    }
    if (req.method !== 'GET' && req.method !== 'HEAD')
      return send(res, 405, 'Так нельзя', { 'content-type': 'text/plain; charset=utf-8' });

    serveStatic(res, p);
  } catch (e) {
    console.error('Упало на', p, '—', e && e.message);
    json(res, 500, { error: 'Что-то сломалось на нашей стороне' });
  }
}).listen(PORT, () => console.log('Scribla слушает порт', PORT));
