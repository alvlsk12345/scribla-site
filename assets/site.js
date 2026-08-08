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

  /* Страниц две, скрипт один. Язык берём из <html lang>, а не из адреса:
     так строка остаётся верной, куда бы страницу ни переложили. */
  const EN = document.documentElement.lang === 'en';

  const T = EN ? {
    sending:  'Sending…',
    badEmail: 'Check the address — looks like a typo',
    tooShort: 'Too short — two words don\'t say what happened',
    offline:  'The server is not responding. Try later or write to alvlsk@me.com',
    notifyOk: 'Noted. We\'ll write once — when it ships.',
    feedbackOk: 'Thank you. Everything gets read.',
    donateSoon: 'Donations open soon',
    pickAmount: 'Choose an amount',
    donate: n => `Send ${n} ₽`,
  } : {
    sending:  'Отправляем…',
    badEmail: 'Проверьте адрес — похоже, в нём опечатка',
    tooShort: 'Слишком коротко — из двух слов не понять, что случилось',
    offline:  'Сервер не отвечает. Попробуйте позже или напишите на alvlsk@me.com',
    notifyOk: 'Записали. Напишем один раз — когда выйдет.',
    feedbackOk: 'Спасибо. Прочитаем всё.',
    donateSoon: 'Приём переводов скоро откроется',
    pickAmount: 'Выберите сумму',
    donate: n => `Перевести ${n} ₽`,
  };

  /* ---------------------------------------------------- шапка */

  const top = $('.top');
  const onScroll = () => top.classList.toggle('stuck', scrollY > 8);
  addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------------------------------------------------- демонстрация

     Примеры настоящие — так продукт и ведёт себя. Придумывать сюда
     красивое нельзя: первый же человек проверит и не найдёт. */

  const DEMO = EN ? [
    { tag: 'Dictation',  said: 'um so anna good morning thanks a lot',
      text: 'Anna, good morning! Thanks a lot.' },
    { tag: 'Dictionary', said: 'send the en dee ay by friday',
      text: 'Send the NDA by Friday.' },
    { tag: 'Maths',      said: 'fifteen percent of two thousand four hundred',
      text: '360' },
    /* Перевод показываем в ту сторону, в которую он и работает:
       говорят по-русски, в поле встаёт по-английски. Придумать
       обратный пример было бы красивее и неправдой. */
    { tag: 'Translation', said: 'документы получены ответим до пятницы',
      text: 'The documents have been received, we will reply by Friday.' },
  ] : [
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
    btn.disabled = true; btn.textContent = T.sending;
    box.className = 'msg';
    try {
      const r = await fetch(url, {
        method: 'POST',
        headers: { 'content-type': 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(data.error || (EN ? 'Could not send' : 'Не получилось отправить'));
      say(box, 'ok', data.message || okText);
      form.reset();
      return true;
    } catch (e) {
      say(box, 'err', e.message === 'Failed to fetch' ? T.offline : e.message);
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
      return say(box, 'err', T.badEmail);
    send(notify, '/api/notify', { email }, box, T.notifyOk);
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
      return say(box, 'err', T.tooShort);
    send(fb, '/api/feedback', { message, email }, box, T.feedbackOk);
  });

  /* ---------------------------------------------------- переводы */

  const custom = $('[data-custom]'), amtInput = $('#amt'), donate = $('[data-donate]');
  let amount = null;

  function refresh() {
    const ok = DONATE_URL && amount > 0;
    donate.setAttribute('aria-disabled', String(!ok));
    donate.href = ok ? DONATE_URL + (DONATE_URL.includes('?') ? '&' : '?') + 'amount=' + amount : '#';
    donate.textContent = !DONATE_URL ? T.donateSoon
      : amount ? T.donate(amount) : T.pickAmount;
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
