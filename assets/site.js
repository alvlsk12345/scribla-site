/* Scribla — сайт. Без библиотек: страница должна открываться быстро
   и с плохой связи, а всё, что здесь есть, укладывается в две сотни строк. */

(() => {
  'use strict';

  const $  = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const calm = matchMedia('(prefers-reduced-motion: reduce)');

  /* Приём переводов — страница CloudTips. Предзаполнение суммы через
     ?amount=N проверено живьём на 100 и 1000. */
  const DONATE_URL = 'https://pay.cloudtips.ru/p/22f5ec26';

  /* Страниц две, скрипт один. Язык берём из <html lang>, а не из адреса:
     так строка остаётся верной, куда бы страницу ни переложили. */
  const LANG = document.documentElement.lang === 'en' ? 'en' : 'ru';
  const EN = LANG === 'en';

  const T = EN ? {
    sending:  'Sending…',
    badEmail: 'Check the address — looks like a typo',
    tooShort: 'Too short — two words don\'t say what happened',
    offline:  'The server is not responding. Try later or write to alvlsk@me.com',
    notifyOk: 'Noted. We\'ll write once — when it ships.',
    feedbackOk: 'Thank you. Everything gets read.',
    pickAmount: 'Choose an amount',
    outOfRange: 'From 10 to 100,000 ₽',
    donate: n => `Send ${n} ₽ via SBP`,
    demoStop: 'Stop the demo',
    demoPlay: 'Play the demo',
    quotes: ['“', '”'],
  } : {
    sending:  'Отправляем…',
    badEmail: 'Проверьте адрес — похоже, в нём опечатка',
    tooShort: 'Слишком коротко — из двух слов не понять, что случилось',
    offline:  'Сервер не отвечает. Попробуйте позже или напишите на alvlsk@me.com',
    notifyOk: 'Записали. Напишем один раз — когда выйдет.',
    feedbackOk: 'Спасибо. Прочитаем всё.',
    pickAmount: 'Выберите сумму',
    outOfRange: 'От 10 до 100 000 ₽',
    donate: n => `Перевести ${n} ₽ через СБП`,
    demoStop: 'Остановить показ',
    demoPlay: 'Показать снова',
    quotes: ['«', '»'],
  };

  /* ---------------------------------------------------- шапка */

  const top = $('.top');
  const onScroll = () => top.classList.toggle('stuck', scrollY > 8);
  addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------------------------------------------------- демонстрация

     Примеры настоящие — так продукт и ведёт себя. Придумывать сюда
     красивое нельзя: первый же человек проверит и не найдёт.

     Первая пара уже отрисована в HTML: пустой телефон при загрузке
     читался как несработавшая картинка, и это было последнее, что
     видел человек на телефоне над сгибом. */

  const DEMO = EN ? [
    { tag: 'Dictation',   said: 'um so anna good morning thanks a lot',
      text: 'Anna, good morning! Thanks a lot.' },
    { tag: 'Dictionary',  said: 'send the en dee ay by friday',
      text: 'Send the NDA by Friday.' },
    { tag: 'Arithmetic',  said: 'fifteen percent of two thousand four hundred',
      text: '360' },
    /* Перевод показываем в ту сторону, в которую он и работает:
       говорят по-русски, в поле встаёт по-английски. */
    { tag: 'Translation', said: 'документы получены ответим до пятницы', saidLang: 'ru',
      text: 'The documents have been received, we will reply by Friday.' },
  ] : [
    { tag: 'Диктовка', said: 'ну э-э аида доброе утро спасибо большое',
      text: 'Аида, доброе утро! Спасибо большое.' },
    { tag: 'Словарь',  said: 'пришлите эн-ди-эй до пятницы',
      text: 'Пришлите NDA до пятницы.', textLang: 'ru' },
    { tag: 'Счёт',     said: 'пятнадцать процентов от двух тысяч четырёхсот',
      text: '360' },
    { tag: 'Перевод',  said: 'документы получены ответим до пятницы',
      text: 'The documents have been received, we will reply by Friday.', textLang: 'en' },
  ];

  const chat = $('[data-chat]'), mic = $('[data-mic]');
  const toggle = $('[data-demo-toggle]');
  const wait = ms => new Promise(r => setTimeout(r, ms));

  let playing = false, cancelled = false;

  function pair(d, done) {
    const el = document.createElement('div');
    el.className = 'pair';

    const said = document.createElement('div');
    said.className = 'said';
    if (d.saidLang) said.lang = d.saidLang;

    const became = document.createElement('div');
    became.className = 'became' + (done ? ' on' : '');
    const tag = document.createElement('span');
    tag.className = 'tag'; tag.textContent = d.tag;
    const txt = document.createElement('span');
    txt.className = 'txt';
    if (d.textLang) txt.lang = d.textLang;
    became.append(tag, txt);

    if (done) { said.textContent = T.quotes[0] + d.said + T.quotes[1]; txt.textContent = d.text; }

    el.append(said, became);
    chat.append(el);
    while (chat.children.length > 3) chat.firstElementChild.remove();
    return { said, txt, became };
  }

  /* Кавычки — часть текста, а не псевдоэлементы: закрывающая уезжала
     на отдельную строку и висела там одна. */
  async function type(el, s) {
    const [open, close] = T.quotes;
    if (calm.matches) { el.textContent = open + s + close; return; }
    el.textContent = open;
    for (const ch of s) {
      if (cancelled) { el.textContent = open + s + close; return; }
      el.textContent = el.textContent.slice(0, -0) + ch;
      await wait(32);
    }
    el.textContent = open + s + close;
  }

  async function run(from) {
    playing = true; cancelled = false;
    for (let i = from; playing; i = (i + 1) % DEMO.length) {
      const d = DEMO[i], p = pair(d, false);
      mic.classList.add('rec');
      await type(p.said, d.said);
      if (!playing) { mic.classList.remove('rec'); return; }
      await wait(300);
      mic.classList.remove('rec');
      p.txt.textContent = d.text;
      p.became.classList.add('on');
      await wait(3600);
    }
  }

  function setToggle(on) {
    if (!toggle) return;
    toggle.setAttribute('aria-pressed', String(!on));
    $('[data-demo-label]', toggle).textContent = on ? T.demoStop : T.demoPlay;
    $('[data-demo-icon]', toggle).innerHTML = on
      ? '<rect x="1.5" y="1" width="3" height="10" rx="1"/><rect x="7.5" y="1" width="3" height="10" rx="1"/>'
      : '<path d="M2.5 1.2 10.8 6l-8.3 4.8z"/>';
  }

  if (chat && mic) {
    /* При «уменьшить движение» цикл не крутится вовсе — раньше он шёл
       вопреки настройке, а это ещё и требование WCAG 2.2.2. Вместо
       анимации показываем все примеры сразу: содержимое важнее показа. */
    if (calm.matches) {
      DEMO.slice(1).forEach(d => pair(d, true));
      chat.style.overflowY = 'auto';
      toggle?.closest('.demo-ctl')?.remove();
    } else {
      setToggle(true);
      const io = new IntersectionObserver(([e]) => {
        if (e.isIntersecting) { io.disconnect(); run(1); }
      });
      io.observe(chat.closest('.phone'));

      toggle?.addEventListener('click', () => {
        if (playing) { playing = false; cancelled = true; setToggle(false); }
        else { setToggle(true); run(0); }
      });
    }
  }

  /* ---------------------------------------------------- формы */

  function say(box, kind, text, field) {
    box.className = 'msg on ' + kind;
    box.textContent = text;
    if (field) {
      field.setAttribute('aria-invalid', kind === 'err' ? 'true' : 'false');
      if (kind === 'err') field.focus();
    }
  }
  const clear = (box, field) => {
    box.className = 'msg';
    field?.setAttribute('aria-invalid', 'false');
  };

  async function send(form, url, body, box, okText, field) {
    const btn = $('button[type=submit]', form);
    const was = btn.textContent;
    btn.disabled = true; btn.textContent = T.sending;
    clear(box, field);
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
      if (fbCount) fbCount.textContent = '0';
      return true;
    } catch (e) {
      say(box, 'err', e.message === 'Failed to fetch' ? T.offline : e.message, field);
      return false;
    } finally {
      btn.disabled = false; btn.textContent = was;
    }
  }

  const notify = $('[data-notify]');
  notify?.addEventListener('submit', e => {
    e.preventDefault();
    const field = $('input[name=email]', notify);
    const email = field.value.trim();
    const box = $('[data-notify-msg]');
    if (!/^[^@\s]+@[^@\s.]+\.[^@\s]{2,}$/.test(email))
      return say(box, 'err', T.badEmail, field);
    send(notify, '/api/notify', { email, lang: LANG }, box, T.notifyOk, field);
  });

  const fb = $('[data-feedback]');
  const fbText = $('#fb-text'), fbCount = $('[data-fb-count]');
  fbText?.addEventListener('input', () => fbCount.textContent = fbText.value.length);
  fb?.addEventListener('submit', e => {
    e.preventDefault();
    const box = $('[data-fb-msg]');
    const message = fbText.value.trim();
    const email = $('input[name=email]', fb).value.trim();
    if (message.length < 10) return say(box, 'err', T.tooShort, fbText);
    send(fb, '/api/feedback', { message, email, lang: LANG }, box, T.feedbackOk, fbText);
  });

  /* ---------------------------------------------------- переводы */

  const custom = $('[data-custom]'), amtInput = $('#amt'), donate = $('[data-donate]');
  let amount = null;

  function refresh() {
    if (!donate) return;
    const ok = Boolean(DONATE_URL) && amount > 0;
    donate.setAttribute('aria-disabled', String(!ok));
    donate.href = ok ? DONATE_URL + '?amount=' + amount : '#';
    donate.textContent = ok ? T.donate(amount)
      : (amtInput && amtInput.value.trim() !== '' ? T.outOfRange : T.pickAmount);
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
    const ok = v >= 10 && v <= 100000;
    amount = ok ? v : null;
    amtInput.setAttribute('aria-invalid', amtInput.value.trim() !== '' && !ok ? 'true' : 'false');
    refresh();
  });

  donate?.addEventListener('click', e => {
    if (donate.getAttribute('aria-disabled') === 'true') e.preventDefault();
  });

  refresh();
})();
