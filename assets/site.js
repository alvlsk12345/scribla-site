/* Scribla — сайт. Без библиотек: страница должна открываться быстро
   и с плохой связи, а всё, что здесь есть, укладывается в сотню строк. */

(() => {
  'use strict';

  const $  = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const slow = matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* Ссылка на приём переводов. Одна строка — меняется, когда заведут
     страницу CloudTips. Пока пусто, кнопка не притворяется рабочей. */
  const DONATE_URL = '';

  /* ---------------------------------------------------- шапка */

  const top = $('.top');
  const onScroll = () => top.classList.toggle('stuck', scrollY > 8);
  addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------------------------------------------------- демонстрация

     Примеры настоящие — так продукт и ведёт себя. Придумывать сюда
     красивое нельзя: первый же человек проверит и не найдёт. */

  const DEMO = [
    { tag: 'Диктовка', said: 'ну э-э аида доброе утро спасибо большое',
      text: 'Аида, доброе утро! Спасибо большое.' },
    { tag: 'Словарь',  said: 'пришлите эн-ди-эй до пятницы',
      text: 'Пришлите NDA до пятницы.' },
    { tag: 'Счёт',     said: 'пятнадцать процентов от двух тысяч четырёхсот',
      text: '360' },
    { tag: 'Перевод',  said: 'документы получены ответим до пятницы',
      text: 'The documents have been received, we will reply by Friday.' },
  ];

  const chat = $('[data-chat]'), mic = $('[data-mic]');
  const wait = ms => new Promise(r => setTimeout(r, ms));

  async function type(el, s, ms) {
    if (slow) { el.textContent = s; return; }
    el.textContent = '';
    for (const ch of s) { el.textContent += ch; await wait(ms); }
  }

  // Пара «сказано → стало». Держим на экране три последних: пустой
  // телефон выглядит незаконченным, а длинная лента уводит взгляд вниз.
  function addPair() {
    const pair = document.createElement('div');
    pair.className = 'pair';
    pair.innerHTML = '<div class="said"></div>'
      + '<div class="became"><span class="tag"></span><span class="txt"></span></div>';
    chat.append(pair);
    while (chat.children.length > 3) chat.firstElementChild.remove();
    return pair;
  }

  async function demo() {
    for (let i = 0; ; i = (i + 1) % DEMO.length) {
      const d = DEMO[i], pair = addPair();
      mic.classList.add('rec');
      await type($('.said', pair), d.said, 32);
      await wait(300);
      mic.classList.remove('rec');
      $('.tag', pair).textContent = d.tag;
      $('.txt', pair).textContent = d.text;
      $('.became', pair).classList.add('on');
      await wait(3600);
    }
  }
  // Не крутим анимацию, пока телефон не показался на экране.
  if (chat) {
    const io = new IntersectionObserver(([e]) => { if (e.isIntersecting) { io.disconnect(); demo(); } });
    io.observe(chat.closest('.phone'));
  }

  /* ---------------------------------------------------- формы */

  function say(box, kind, text) {
    box.className = 'msg on ' + kind;
    box.textContent = text;
  }

  async function send(form, url, body, box, okText) {
    const btn = $('button[type=submit]', form);
    const was = btn.textContent;
    btn.disabled = true; btn.textContent = 'Отправляем…';
    box.className = 'msg';
    try {
      const r = await fetch(url, {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(data.error || 'Не получилось отправить');
      say(box, 'ok', data.message || okText);
      form.reset();
      return true;
    } catch (e) {
      say(box, 'err', e.message === 'Failed to fetch'
        ? 'Сервер не отвечает. Попробуйте позже или напишите на alvlsk@me.com'
        : e.message);
      return false;
    } finally {
      btn.disabled = false; btn.textContent = was;
    }
  }

  const notify = $('[data-notify]');
  notify?.addEventListener('submit', e => {
    e.preventDefault();
    const email = $('input[name=email]', notify).value.trim();
    const box = $('[data-notify-msg]');
    if (!/^[^@\s]+@[^@\s.]+\.[^@\s]{2,}$/.test(email))
      return say(box, 'err', 'Проверьте адрес — похоже, в нём опечатка');
    send(notify, '/api/notify', { email }, box, 'Записали. Напишем один раз — когда выйдет.');
  });

  const fb = $('[data-feedback]');
  const fbText = $('#fb-text'), fbCount = $('[data-fb-count]');
  fbText?.addEventListener('input', () => fbCount.textContent = fbText.value.length);
  fb?.addEventListener('submit', e => {
    e.preventDefault();
    const box = $('[data-fb-msg]');
    const message = fbText.value.trim();
    const email = $('input[name=email]', fb).value.trim();
    if (message.length < 10)
      return say(box, 'err', 'Слишком коротко — из двух слов не понять, что случилось');
    send(fb, '/api/feedback', { message, email }, box, 'Спасибо. Прочитаем всё.');
  });

  /* ---------------------------------------------------- переводы */

  const custom = $('[data-custom]'), amtInput = $('#amt'), donate = $('[data-donate]');
  let amount = null;

  function refresh() {
    const ok = DONATE_URL && amount > 0;
    donate.setAttribute('aria-disabled', String(!ok));
    donate.href = ok ? DONATE_URL + (DONATE_URL.includes('?') ? '&' : '?') + 'amount=' + amount : '#';
    donate.textContent = !DONATE_URL ? 'Приём переводов скоро откроется'
      : amount ? `Перевести ${amount} ₽` : 'Выберите сумму';
  }

  $$('.amount').forEach(b => b.addEventListener('click', () => {
    $$('.amount').forEach(o => o.setAttribute('aria-pressed', String(o === b)));
    const v = b.dataset.amount;
    custom.hidden = v !== 'custom';
    amount = v === 'custom' ? (parseInt(amtInput.value, 10) || null) : parseInt(v, 10);
    if (v === 'custom') amtInput.focus();
    refresh();
  }));

  amtInput?.addEventListener('input', () => {
    const v = parseInt(amtInput.value, 10);
    amount = v >= 10 && v <= 100000 ? v : null;
    refresh();
  });

  donate?.addEventListener('click', e => {
    if (donate.getAttribute('aria-disabled') === 'true') e.preventDefault();
  });

  refresh();
})();
