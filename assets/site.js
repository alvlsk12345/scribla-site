/* Scribla — сайт. Без библиотек: страница должна открываться быстро
   и с плохой связи, а всё, что здесь есть, укладывается в две сотни строк. */

(() => {
  'use strict';

  const $  = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const calm = matchMedia('(prefers-reduced-motion: reduce)');

  /* Страниц четыре, скрипт один. Язык берём из <html lang>, а не из
     адреса: так строка остаётся верной, куда бы страницу ни переложили.
     Обрезаем до двух букв — у китайской страницы объявлено `zh-Hans`
     (упрощённое письмо), и сравнение целой строки промахнулось бы. */
  const LANG = (document.documentElement.lang || 'ru').slice(0, 2);
  const RU = LANG === 'ru';

  /* Приём переводов. Рельсы РАЗНЫЕ, и это не украшение: СБП работает
     только из российских банков, а карта из США или Европы там не пройдёт
     вовсе. Поэтому русская страница ведёт на CloudTips, а все остальные —
     на Gumroad. Gumroad выбран из проверенных по одной причине: Казахстан
     стоит у него в официальной таблице выплат (валюта KZT, зачисление
     на счёт), тогда как у Buy Me a Coffee, Ko-fi и GitHub Sponsors выплаты
     идут через Stripe, а Stripe в Казахстане не работает. Он же merchant
     of record — НДС и sales tax по всем странам считает и платит сам.

     Испанская и китайская страницы идут той же рельсой, что английская,
     и по той же причине: карта у человека выпущена не в России, а Gumroad
     принимает карты откуда угодно. Разделение здесь одно — «Россия или
     не Россия», языков это не касается вовсе.

     Предзаполнение суммы: у CloudTips ?amount=N (проверено живьём на 100
     и 1000, хотя в документации описан только виджет), у Gumroad
     ?price=N&wanted=true — второй параметр ведёт сразу на оплату, минуя
     карточку товара.

     Виджетов ни там, ни там: оба тянут чужой скрипт на страницу, где
     написано, что ничего никуда не уходит. */
  const DONATE = RU ? {
    url:   'https://pay.cloudtips.ru/p/22f5ec26',
    query: n => `?amount=${n}`,
    min: 10, max: 100000,
  } : {
    url:   'https://scribla.gumroad.com/l/thanks',   /* ← сюда адрес товара */
    query: n => `?price=${n}&wanted=true`,
    min: 3, max: 500,
  };

  /* Строки скрипта. Ключ — двухбуквенный язык страницы; чего нет,
     то берётся из английского, а не молчит пустотой. */
  const TEXTS = {};
  TEXTS.en = {
    sending:  'Sending…',
    badEmail: 'Check the address — looks like a typo',
    tooShort: 'Too short — two words don\'t say what happened',
    offline:  'The server is not responding. Try later or write to dev@scribla.io',
    notifyOk: 'Noted. We\'ll write once — when it ships.',
    feedbackOk: 'Thank you. Everything gets read.',
    pickAmount: 'Choose an amount',
    outOfRange: 'From $3 to $500',
    donate: n => `Send $${n}`,
    demoStop: 'Stop the demo',
    demoPlay: 'Play the demo',
    quotes: ['“', '”'],
    shotsMany: 'Kept the first three. Three is the limit.',
    shotsBad: 'The browser could not open this image. PNG or JPEG works.',
    shotOff: n => `Remove screenshot ${n}`,
    soundOn:  'With sound',
    soundOff: 'Mute',
    filmPlay: 'Play with sound',
    cantSend: 'Could not send',
    kb: ' KB', mb: ' MB', dot: '.',
  };
  TEXTS.ru = {
    sending:  'Отправляем…',
    badEmail: 'Проверьте адрес — похоже, в нём опечатка',
    tooShort: 'Слишком коротко — из двух слов не понять, что случилось',
    offline:  'Сервер не отвечает. Попробуйте позже или напишите на dev@scribla.io',
    notifyOk: 'Записали. Напишем один раз — когда выйдет.',
    feedbackOk: 'Спасибо. Прочитаем всё.',
    pickAmount: 'Выберите сумму',
    outOfRange: 'От 10 до 100 000 ₽',
    donate: n => `Перевести ${n} ₽ через СБП`,
    demoStop: 'Остановить показ',
    demoPlay: 'Показать снова',
    quotes: ['«', '»'],
    shotsMany: 'Взяли первые три — больше не нужно',
    shotsBad: 'Эту картинку браузер не открыл. Подойдёт PNG или JPEG.',
    shotOff: n => `Убрать скриншот ${n}`,
    soundOn:  'Со звуком',
    soundOff: 'Без звука',
    filmPlay: 'Смотреть со звуком',
    cantSend: 'Не получилось отправить',
    kb: ' КБ', mb: ' МБ', dot: ',',
  };
  TEXTS.es = {
    sending:  'Enviando…',
    badEmail: 'Revisa la dirección — parece que tiene una errata',
    tooShort: 'Demasiado corto — con dos palabras no se entiende qué pasó',
    offline:  'El servidor no responde. Inténtalo más tarde o escribe a dev@scribla.io',
    notifyOk: 'Apuntado. Escribiremos una vez: cuando salga.',
    feedbackOk: 'Gracias. Lo leemos todo.',
    pickAmount: 'Elige una cantidad',
    outOfRange: 'De 3 a 500 $',
    donate: n => `Enviar ${n} $`,
    demoStop: 'Parar la demostración',
    demoPlay: 'Ver otra vez',
    quotes: ['«', '»'],
    shotsMany: 'Nos quedamos con las tres primeras. Tres es el límite.',
    shotsBad: 'El navegador no pudo abrir esta imagen. Sirve PNG o JPEG.',
    shotOff: n => `Quitar la captura ${n}`,
    soundOn:  'Con sonido',
    soundOff: 'Sin sonido',
    filmPlay: 'Ver con sonido',
    cantSend: 'No se pudo enviar',
    kb: ' KB', mb: ' MB', dot: ',',
  };
  /* Китайский набор. Кавычки здесь свои: у упрощённого письма это “ ”
     по ГОСТу КНР (GB/T 15834), а не 「 」 — те приняты на Тайване
     и в Гонконге, и на странице, объявленной zh-Hans, читаются чужими. */
  TEXTS.zh = {
    sending:  '正在发送…',
    badEmail: '请检查邮箱地址，看起来有笔误',
    tooShort: '太短了——两个词说不清发生了什么',
    offline:  '服务器没有响应。请稍后再试，或写信到 dev@scribla.io',
    notifyOk: '已记下。发布的时候我们只写一封信。',
    feedbackOk: '谢谢。每一条我们都会读。',
    pickAmount: '请选择金额',
    outOfRange: '3 到 500 美元',
    donate: n => `发送 ${n} 美元`,
    demoStop: '停止演示',
    demoPlay: '再看一遍',
    quotes: ['“', '”'],
    shotsMany: '只保留前三张，三张是上限。',
    shotsBad: '浏览器打不开这张图片。PNG 或 JPEG 可以。',
    shotOff: n => `移除第 ${n} 张截图`,
    soundOn:  '带声音',
    soundOff: '静音',
    filmPlay: '带声音观看',
    cantSend: '没能发送',
    kb: ' KB', mb: ' MB', dot: '.',
  };

  const T = TEXTS[LANG] || TEXTS.en;

  /* ---------------------------------------------------- шапка */

  const top = $('.top');
  const onScroll = () => top.classList.toggle('stuck', scrollY > 8);
  addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  /* ---------------------------------------------------- приход на раздел

     Приложение зовёт на «/#support», а браузер оставлял человека
     на первом экране: до раздела он не доезжал вовсе.

     Виноваты две черты страницы вместе. Прокрутка объявлена плавной,
     поэтому к якорю браузер не прыгает, а едет — и едет долго, шесть
     с половиной тысяч точек. По дороге дозагружаются картинки, часть
     из них без заранее объявленных размеров, высоты меняются, и поездка
     обрывается там, где началась. Проверено: и Safari на телефоне,
     и Chrome на столе остаются на нуле.

     Отнимать плавность у ссылок в шапке ради этого незачем — там
     она к месту. Поэтому доводим сами: когда страница собрана и высоты
     больше не поедут, встаём на раздел разом. Отступ под шапку
     `scrollIntoView` берёт из `scroll-margin-top` секции.

     Если человек к этому времени тронул страницу сам — не трогаем:
     его прокрутка старше нашей. */

  let touched = false;
  for (const evt of ['wheel', 'touchstart', 'pointerdown', 'keydown'])
    addEventListener(evt, () => { touched = true; }, { passive: true, once: true });

  const jumpToHash = () => {
    if (touched) return;
    const id = decodeURIComponent(location.hash.slice(1));
    const target = id && document.getElementById(id);
    if (!target) return;

    const html = document.documentElement;
    const smooth = html.style.scrollBehavior;
    html.style.scrollBehavior = 'auto';
    target.scrollIntoView();
    html.style.scrollBehavior = smooth;
  };

  if (location.hash) {
    /* Дважды: шрифты приходят раньше картинок и уже двигают текст,
       а `load` — последняя точка, после которой высоты неизменны.
       Второй прыжок с той же высоты ничего не делает и не виден. */
    document.fonts?.ready.then(jumpToHash);
    addEventListener('load', jumpToHash);
  }

  /* ---------------------------------------------------- ролик

     Немой автозапуск — не осторожность, а единственный разрешённый:
     со звуком браузер не даст стартовать вообще, и человек увидит
     стоп-кадр вместо ролика. Кнопка включает голос и отматывает
     к началу: фраза звучит в первую секунду, и без перемотки
     достался бы только её хвост.

     При отключённой анимации и в режиме экономии трафика ролик ждёт
     на постере: сам он не начнётся, но запустить можно всегда. */

  const film = $('[data-film]');
  if (film) {
    const filmBtn = $('[data-film-sound]');
    const filmLbl = $('[data-film-label]');
    const still = calm.matches || navigator.connection?.saveData === true;

    /* «Смотреть со звуком» — только там, где ролик сам не пойдёт.
       Привязывать надпись к текущей паузе нельзя: до прокрутки видео
       ещё стоит, и кнопка меняла слова у человека на глазах, хотя
       ничего не менялось. */
    const filmLabel = () => {
      filmBtn.setAttribute('aria-pressed', String(!film.muted));
      filmLbl.textContent = !film.muted ? T.soundOff
        : (still ? T.filmPlay : T.soundOn);
    };

    let heard = 0;          // докуда ролик дошёл со звуком

    filmBtn.addEventListener('click', () => {
      if (film.muted) {
        film.muted = false;
        film.currentTime = 0;
        heard = 0;
        film.play().catch(() => {});
      } else {
        film.muted = true;
      }
      filmLabel();
    });

    /* Со звуком ролик идёт ровно один раз, дальше крутится молча.
       Восемь секунд с голосом и музыкой человек послушает охотно,
       те же восемь по третьему кругу — уже навязчивость, а свернуть
       их нечем: кнопка одна и она про звук, не про повтор.

       Событие конца при loop не приходит вовсе, поэтому круг ловится
       по времени. Проверять «время пошло назад» мало: у нажатия
       и перемотки на ноль тот же признак, и звук выключался в ту же
       секунду, в которую его включили, — поймано на стенде. Признак
       верный — «дошли почти до конца И вернулись в начало».

       Счётчик растёт только мелкими шагами: событие о времени иногда
       приходит уже после того, как кнопка отмотала ролик на ноль,
       и одиночный скачок с нуля к концу выдал бы круг там, где его
       не было. Нажали в последние полсекунды немого круга — звук
       выключился бы сразу. Пропущенный круг не страшен: ролик просто
       уйдёт на второй, как и было до этой правки. */
    film.addEventListener('timeupdate', () => {
      if (film.muted) { heard = 0; return; }
      if (heard > film.duration - 0.6 && film.currentTime < heard - 1) {
        film.muted = true;
        heard = 0;
        filmLabel();
        return;
      }
      const t = film.currentTime;
      if (t > heard && t - heard < 1.5) heard = t;
    });

    /* Пролистали мимо — звук снимаем сами. Голос из блока, которого
       уже не видно, звучит как чужое окно, открывшееся за спиной. */
    new IntersectionObserver(([e]) => {
      if (e.isIntersecting) {
        if (!still && film.paused) film.play().catch(() => {});
      } else if (!film.paused) {
        film.muted = true;
        film.pause();
      }
      filmLabel();
    }, { threshold: 0.25 }).observe(film);

    filmLabel();
  }

  /* ---------------------------------------------------- демонстрация

     Примеры настоящие — так продукт и ведёт себя. Придумывать сюда
     красивое нельзя: первый же человек проверит и не найдёт.

     Первая пара уже отрисована в HTML: пустой телефон при загрузке
     читался как несработавшая картинка, и это было последнее, что
     видел человек на телефоне над сгибом. */

  const DEMOS = {};
  DEMOS.en = [
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
  ];
  DEMOS.ru = [
    { tag: 'Диктовка', said: 'ну э-э аида доброе утро спасибо большое',
      text: 'Аида, доброе утро! Спасибо большое.' },
    { tag: 'Словарь',  said: 'пришлите эн-ди-эй до пятницы',
      text: 'Пришлите NDA до пятницы.', textLang: 'ru' },
    { tag: 'Счёт',     said: 'пятнадцать процентов от двух тысяч четырёхсот',
      text: '360' },
    { tag: 'Перевод',  said: 'документы получены ответим до пятницы',
      text: 'The documents have been received, we will reply by Friday.', textLang: 'en' },
  ];
  DEMOS.es = [
    { tag: 'Dictado',     said: 'eh bueno ana buenos días muchas gracias',
      text: 'Ana, buenos días. ¡Muchas gracias!' },
    { tag: 'Diccionario', said: 'manda el ene de a antes del viernes',
      text: 'Manda el NDA antes del viernes.' },
    { tag: 'Cálculo',     said: 'el quince por ciento de dos mil cuatrocientos',
      text: '360' },
    { tag: 'Traducción',  said: 'documentos recibidos responderemos antes del viernes',
      text: 'The documents have been received, we will reply by Friday.', textLang: 'en' },
  ];
  /* Китайские примеры взяты со стенда распознавания, а не сочинены.
     Знаков препинания китайская модель не ставит вовсе — их дописывает
     отдельная модель пунктуации, и первая строка выглядит именно так:
     сплошная лента иероглифов без запятых и без вопросительного знака.
     Словарь замен показан на однозвучных именах: 张伟 и 章玮 читаются
     одинаково (zhāng wěi), и распознавание берёт частое написание.
     Кавычки к репликам приставляет T.quotes — здесь “ ”, не 「 」. */
  DEMOS.zh = [
    { tag: '听写',   said: '周二上午十点半的会议你能确认一下吗',
      text: '周二上午十点半的会议，你能确认一下吗？' },
    { tag: '词典',   said: '请把方案发给张伟',
      text: '请把方案发给章玮。' },
    { tag: '计算',   said: '两千四百的百分之十五',
      text: '360' },
    { tag: '翻译',   said: '文件已收到我们会在周五之前回复',
      text: 'The documents have been received, we will reply by Friday.', textLang: 'en' },
  ];

  const DEMO = DEMOS[LANG] || DEMOS.en;

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
      // Со скриншотами уходит FormData. Заголовок ей задаёт браузер
      // сам — вписать свой значит потерять границу частей и получить
      // на сервере пустой запрос.
      const form_ = body instanceof FormData;
      const r = await fetch(url, {
        method: 'POST',
        headers: form_ ? undefined : { 'content-type': 'application/json' },
        body: form_ ? body : JSON.stringify(body),
      });
      const data = await r.json().catch(() => ({}));
      if (!r.ok) throw new Error(data.error || T.cantSend);
      say(box, 'ok', data.message || okText);
      form.reset();
      if (fbCount) fbCount.textContent = '0';
      if (form === fb) shots.clear();
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

  /* ------------------------------------------------- скриншоты к отзыву

     Картинку ужимаем здесь, до отправки, и на то три причины сразу.
     Скриншот с телефона — это пять мегабайт, которые с мобильной связи
     уходят полминуты. Такое же письмо потом падает владельцу в ящик.
     И вместе с исходным файлом уезжают служебные поля: снято тогда-то,
     там-то. Пересъёмка через canvas отрезает всё это заодно.

     Ширина 1600 выбрана не наугад: это скриншот айфона в натуральную
     величину. Мельче — текст на картинке перестаёт читаться, а именно
     ради текста скриншот и присылают. */

  const MAX_SHOTS = 3, MAX_SIDE = 1600;

  const shots = (() => {
    const zone = $('[data-drop]'), input = $('[data-shots]'), list = $('[data-shot-list]');
    const items = [];
    if (!zone || !input || !list) {
      return { all: () => [], clear() {} };
    }

    const weigh = n => n < 1048576
      ? Math.round(n / 1024) + T.kb
      : (n / 1048576).toFixed(1).replace('.', T.dot) + T.mb;

    function draw() {
      list.textContent = '';
      items.forEach((it, i) => {
        const li = document.createElement('li');

        const img = document.createElement('img');
        img.src = it.url; img.alt = '';

        const who = document.createElement('div');
        who.className = 'who';
        const name = document.createElement('b');
        name.textContent = it.name;
        const size = document.createElement('span');
        size.textContent = weigh(it.blob.size);
        who.append(name, size);

        const off = document.createElement('button');
        off.type = 'button'; off.className = 'drop-x';
        off.textContent = '×';
        off.setAttribute('aria-label', T.shotOff(i + 1));
        off.addEventListener('click', () => {
          URL.revokeObjectURL(it.url);
          items.splice(i, 1);
          draw();
          input.focus();
        });

        li.append(img, who, off);
        list.append(li);
      });
      // Поле файлов очищаем всегда: список ведём мы, и выбранный второй
      // раз тот же файл иначе не вызовет события change.
      input.value = '';
    }

    /* Пересъёмка на canvas. WebP держит мелкий текст заметно лучше
       JPEG при том же весе; где его нет — уходим на JPEG. */
    async function shrink(file) {
      const bmp = await createImageBitmap(file);
      const k = Math.min(1, MAX_SIDE / Math.max(bmp.width, bmp.height));
      const c = document.createElement('canvas');
      c.width = Math.round(bmp.width * k);
      c.height = Math.round(bmp.height * k);
      const ctx = c.getContext('2d', { alpha: false });
      ctx.imageSmoothingQuality = 'high';
      ctx.drawImage(bmp, 0, 0, c.width, c.height);
      bmp.close?.();

      const shot = t => new Promise(r => c.toBlob(r, t, .82));
      let blob = await shot('image/webp');
      if (!blob || blob.type !== 'image/webp') { blob = await shot('image/jpeg'); }
      return blob;
    }

    async function add(files) {
      const box = $('[data-fb-msg]');
      const room = MAX_SHOTS - items.length;
      const pics = [...files].filter(f => f.type.startsWith('image/'));

      // Лишнее отбрасываем вслух. Молча урезанный список выглядит так,
      // будто картинка приложилась, — и человек узнаёт обратное никогда.
      if (pics.length > room) { say(box, 'err', T.shotsMany); }
      if (room <= 0) { return; }

      for (const file of pics.slice(0, room)) {
        try {
          const blob = await shrink(file);
          if (!blob) { throw new Error('пусто'); }
          items.push({
            blob,
            url: URL.createObjectURL(blob),
            name: (file.name || 'screenshot').replace(/\.[^.]+$/, '')
                  + (blob.type === 'image/webp' ? '.webp' : '.jpg'),
          });
        } catch {
          say(box, 'err', T.shotsBad);
        }
      }
      draw();
    }

    input.addEventListener('change', () => add(input.files));

    ['dragenter', 'dragover'].forEach(t => zone.addEventListener(t, e => {
      e.preventDefault(); zone.classList.add('over');
    }));
    ['dragleave', 'drop'].forEach(t => zone.addEventListener(t, () => zone.classList.remove('over')));
    zone.addEventListener('drop', e => {
      e.preventDefault();
      if (e.dataTransfer?.files.length) { add(e.dataTransfer.files); }
    });

    /* Вставка из буфера — главный способ приложить скриншот на настольном
       компьютере: снял область, встал в форму, ⌘V. Слушаем всю форму,
       а не рамку: попасть в неё курсором перед вставкой никто не станет. */
    fb?.addEventListener('paste', e => {
      const pics = [...(e.clipboardData?.items || [])]
        .filter(i => i.kind === 'file' && i.type.startsWith('image/'))
        .map(i => i.getAsFile())
        .filter(Boolean);
      if (!pics.length) { return; }
      e.preventDefault();
      add(pics);
    });

    return {
      all: () => items,
      clear() {
        items.forEach(it => URL.revokeObjectURL(it.url));
        items.length = 0;
        draw();
      },
    };
  })();

  fb?.addEventListener('submit', e => {
    e.preventDefault();
    const box = $('[data-fb-msg]');
    const message = fbText.value.trim();
    const email = $('input[name=email]', fb).value.trim();
    if (message.length < 10) return say(box, 'err', T.tooShort, fbText);

    const pics = shots.all();
    if (!pics.length) {
      return send(fb, '/api/feedback', { message, email, lang: LANG }, box, T.feedbackOk, fbText);
    }
    const data = new FormData();
    data.append('message', message);
    data.append('email', email);
    data.append('lang', LANG);
    pics.forEach(p => data.append('shots[]', p.blob, p.name));
    send(fb, '/api/feedback', data, box, T.feedbackOk, fbText);
  });

  /* ---------------------------------------------------- переводы */

  const custom = $('[data-custom]'), amtInput = $('#amt'), donate = $('[data-donate]');
  let amount = null;

  function refresh() {
    if (!donate) return;
    const ok = Boolean(DONATE.url) && amount > 0;
    donate.setAttribute('aria-disabled', String(!ok));
    donate.href = ok ? DONATE.url + DONATE.query(amount) : '#';
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
    const ok = v >= DONATE.min && v <= DONATE.max;
    amount = ok ? v : null;
    amtInput.setAttribute('aria-invalid', amtInput.value.trim() !== '' && !ok ? 'true' : 'false');
    refresh();
  });

  donate?.addEventListener('click', e => {
    if (donate.getAttribute('aria-disabled') === 'true') e.preventDefault();
  });

  refresh();
})();
