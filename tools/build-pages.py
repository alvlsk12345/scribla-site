#!/usr/bin/env python3
"""Сборка четырёх языковых страниц из одного макета.

Появился 22 августа 2026 вместе с переработкой лендинга: до этого четыре
index.html правились руками по очереди, и они расходились — в одном
кнопка вела на образ, в другом на якорь. Теперь разметка одна, языки —
таблицы строк ниже. Что не меняется по языкам (форма отзывов, переводы,
ответы FAQ) — берётся из прежних страниц (tools/legacy/<lang>.html),
чтобы не переписывать выверенные тексты.

Запуск: python3 tools/build-pages.py  (из корня репозитория).
"""
import re, pathlib, html

ROOT = pathlib.Path(__file__).resolve().parent.parent
LEGACY = ROOT / 'tools' / 'legacy'
SHA = '21d193c0d73ece315edb727280fd067b8185700b46a2a1cd44bed7826f274c61'
STORE = 'https://apps.apple.com/app/id6800086470'
DMG = '/download/Scribla-1.0.dmg'

S = {}

S['ru'] = dict(
  out='index.html', pre='', lang='ru', cur='RU', shots='ru', demo='ru',
  nav=[('#how','Как работает'),('#modes','Возможности'),('#mac','Для Mac'),('#privacy','Приватность'),('#faq','Вопросы')],
  top='Скачать', langlabel='Язык',
  h1='Сказали&nbsp;— текст уже в&nbsp;поле',
  lead='Диктовка для iPhone и Mac. Ставит знаки, убирает «э-э», считает, переводит и отвечает на вопрос. Бесплатно, звук остаётся на устройстве.',
  ios='Скачать на iPhone', mac='Скачать для Mac',
  meta_mac='macOS 14 и новее · Apple Silicon и Intel · 25&nbsp;МБ · подписано Apple',
  meta_ios='iOS 17 и новее · четыре языка · без регистрации',
  alt_h='Scribla работает на iPhone и Mac', alt_p='С телефона — откройте в App Store или наведите камеру на код.',
  alt_mail='Оставьте почту — напишем, когда будут новости.', alt_btn='Сообщать о новостях', alt_ph='вы@почта.ru',
  badge='appstore-badge-ru-ru.svg', badge_alt='Загрузите в App Store', qr_alt='QR-код: Scribla в App Store',
  sound_on='Со звуком', video_alt='Запись экрана: клавиатура Scribla в «Напоминаниях» — сказанное встаёт в поле готовым текстом',
  said='Сказали', got='В поле',
  modes_h='Четыре режима. Режим решает, что встанет в поле',
  modes_p='Выбранный режим держится, пока его не сменят: говорить «посчитай» или «переведи» не нужно.',
  modes=[
    ('Текст','ну э-э аида доброе утро спасибо большое','Аида, доброе утро! Спасибо большое.'),
    ('Счёт','пятнадцать процентов от двух тысяч четырёхсот','360'),
    ('Перевод','документы получены, ответим до пятницы','The documents have been received, we will reply by Friday.'),
    ('AI','ответь что в четверг не получится предложи пятницу','К сожалению, в четверг не получится. Предлагаю перенести встречу на пятницу.'),
  ],
  modes_note='Словарь «слышится → пишется» чинит имена и термины раз и навсегда: поправили «эн-ди-эй» один раз — дальше всегда NDA.',
  how_h='Три шага, и первый — один раз',
  steps=[
    ('Включить клавиатуру','Настройки → Основные → Клавиатура → Клавиатуры → Scribla. Минута, и больше к этому не возвращаться.','main'),
    ('Нажать на Дьяка','В любом поле ввода: мессенджер, почта, заметки, поиск. Дьяк — кнопка записи на клавиатуре.','dictation'),
    ('Говорить','Текст встанет в поле сам. Если распознало не то — полоса правки рядом: слово меняется отдельно, всю фразу переписывать не нужно.','wordpanel'),
  ],
  mac_h='На маке — то же самое, одной клавишей',
  mac_p='Значок в строке меню. Держите правый ⌘, говорите — текст встаёт под курсор в любом окне. ⌘ + ⌥ — ответ на сказанное, с нажатым / — перевод.',
  mac_facts=['Образ подписан и заверен у Apple: открывается двойным щелчком','Apple Silicon и Intel, macOS 14 и новее','Диктовка и словарь без интернета; ответ и перевод — модель'],
  mac_btn='Скачать для Mac', sha='SHA-256 образа',
  mac_img_alt='Плашка Scribla на маке во время диктовки',
  privacy_h='Звук никуда не уходит',
  privacy=[
    ('Распознавание на устройстве','Речь разбирает сам телефон или мак. Запись не покидает его, и серверу нечего хранить.'),
    ('Что уходит в режимах с AI','Только текст вопроса — на scribla.io, оттуда к модели. Ни вопрос, ни ответ не сохраняются. Режимы выключаются в настройках.'),
    ('Без аккаунта и рекламы','Ни регистрации, ни карты, ни трекеров. Вебвизора на сайте тоже нет.'),
  ],
  privacy_link='Политика приватности целиком', privacy_href='privacy.html',
  dl_h='Скачать Scribla', dl_p='Бесплатно и там, и там.',
  dl_ios_h='iPhone', dl_ios_meta='Версия 1.2 · iOS 17 и новее',
  dl_mac_h='Mac', dl_mac_meta='Версия 1.0 · 24,7 МБ · macOS 14 и новее',
  faq_h='Вопросы', faq_pick=[0,1,4,5,8,11],
  contact_h='Написать нам',
  footer_about='Scribla — диктовка для iPhone и Mac. Делает небольшая команда.',
  f_links=[('privacy.html','Политика приватности'),('support.html','Помощь'),('#feedback','Написать'),('#support','Поддержать')],
)

S['en'] = dict(
  out='en/index.html', pre='../', lang='en', cur='EN', shots='en', demo='en',
  nav=[('#how','How it works'),('#modes','Modes'),('#mac','For Mac'),('#privacy','Privacy'),('#faq','FAQ')],
  top='Download', langlabel='Language',
  h1='Say it. It is already in&nbsp;the&nbsp;field.',
  lead='Dictation for iPhone and Mac. Punctuation in, the ums out. It also counts, translates and answers questions. Free, and the audio stays on the device.',
  ios='Get it for iPhone', mac='Download for Mac',
  meta_mac='macOS 14 or later · Apple Silicon and Intel · 25&nbsp;MB · signed by Apple',
  meta_ios='iOS 17 or later · four languages · no sign-up',
  alt_h='Scribla runs on iPhone and Mac', alt_p='On your phone, open the App Store or point the camera at the code.',
  alt_mail='Leave an email and we will write when there is news.', alt_btn='Keep me posted', alt_ph='you@example.com',
  badge='appstore-badge-en-us.svg', badge_alt='Download on the App Store', qr_alt='QR code: Scribla on the App Store',
  sound_on='With sound', video_alt='Screen recording: the Scribla keyboard in Reminders turns speech into finished text',
  said='You say', got='In the field',
  modes_h='Four modes. The mode decides what lands in the field',
  modes_p='The chosen mode stays until you change it: no need to say “calculate” or “translate”.',
  modes=[
    ('Text','um so hi aida good morning thanks a lot','Hi Aida, good morning! Thanks a lot.'),
    ('Calc','fifteen percent of two thousand four hundred','360'),
    ('Translate','documents received, we will reply by friday','Documentos recibidos, respondemos antes del viernes.'),
    ('AI','reply that thursday does not work and suggest friday','Unfortunately, Thursday does not work for me. Could we move the meeting to Friday?'),
  ],
  modes_note='A “sounds like → spelled as” dictionary fixes names and terms for good: correct “en dee ay” once and it is NDA from then on.',
  how_h='Three steps, and the first one only once',
  steps=[
    ('Enable the keyboard','Settings → General → Keyboard → Keyboards → Scribla. A minute, and you never come back to it.','main'),
    ('Tap the scribe','In any text field: messenger, mail, notes, search. The bearded scribe is the record button on the keyboard.','dictation'),
    ('Speak','The text lands in the field by itself. Misheard a word? The edit bar is right there: change one word, not the whole phrase.','wordpanel'),
  ],
  mac_h='On the Mac, the same thing with one key',
  mac_p='An icon in the menu bar. Hold the right ⌘ and speak: the text lands at the cursor in any window. ⌘ + ⌥ answers what you said; add / for a translation.',
  mac_facts=['The image is signed and notarized by Apple: opens with a double click','Apple Silicon and Intel, macOS 14 or later','Dictation and dictionary work offline; answers and translation use the model'],
  mac_btn='Download for Mac', sha='SHA-256 of the image',
  mac_img_alt='The Scribla pill on the Mac while dictating',
  privacy_h='The audio never leaves',
  privacy=[
    ('Recognition on the device','Your phone or Mac does the listening. The recording never leaves it, so there is nothing for a server to keep.'),
    ('What leaves in AI modes','Only the text of your question, to scribla.io and on to the model. Neither question nor answer is stored. The modes switch off in Settings.'),
    ('No account, no ads','No sign-up, no card, no trackers. No session recording on this site either.'),
  ],
  privacy_link='Full privacy policy', privacy_href='../privacy.html#en',
  dl_h='Download Scribla', dl_p='Free on both.',
  dl_ios_h='iPhone', dl_ios_meta='Version 1.2 · iOS 17 or later',
  dl_mac_h='Mac', dl_mac_meta='Version 1.0 · 24.7 MB · macOS 14 or later',
  faq_h='Questions', faq_pick=[0,1,4,5,8,12],
  contact_h='Write to us',
  footer_about='Scribla is dictation for iPhone and Mac, made by a small team.',
  f_links=[('../privacy.html#en','Privacy policy'),('../support.html','Help'),('#feedback','Write in'),('#support','Support')],
)

S['es'] = dict(
  out='es/index.html', pre='../', lang='es', cur='ES', shots='en', demo='en',
  nav=[('#how','Cómo funciona'),('#modes','Modos'),('#mac','Para Mac'),('#privacy','Privacidad'),('#faq','Preguntas')],
  top='Descargar', langlabel='Idioma',
  h1='Dicho, y&nbsp;ya está en&nbsp;el&nbsp;campo',
  lead='Dictado para iPhone y Mac. Pone los signos, quita los «eh», calcula, traduce y responde preguntas. Gratis, y el audio se queda en el equipo.',
  ios='Descargar para iPhone', mac='Descargar para Mac',
  meta_mac='macOS 14 o posterior · Apple Silicon e Intel · 25&nbsp;MB · firmado por Apple',
  meta_ios='iOS 17 o posterior · cuatro idiomas · sin registro',
  alt_h='Scribla funciona en iPhone y Mac', alt_p='Desde el teléfono, ábrela en el App Store o apunta la cámara al código.',
  alt_mail='Deja tu correo y te escribimos cuando haya novedades.', alt_btn='Avisarme', alt_ph='tu@correo.com',
  badge='appstore-badge-es-es.svg', badge_alt='Consíguelo en el App Store', qr_alt='Código QR: Scribla en el App Store',
  sound_on='Con sonido', video_alt='Grabación de pantalla: el teclado Scribla en Recordatorios convierte la voz en texto listo',
  said='Dices', got='En el campo',
  modes_h='Cuatro modos. El modo decide qué aparece en el campo',
  modes_p='El modo elegido se mantiene hasta que lo cambies: no hace falta decir «calcula» o «traduce».',
  modes=[
    ('Texto','eh hola aida buenos días muchas gracias','Hola, Aida, ¡buenos días! Muchas gracias.'),
    ('Cálculo','el quince por ciento de dos mil cuatrocientos','360'),
    ('Traducción','documentos recibidos, respondemos antes del viernes','Documents received, we will reply by Friday.'),
    ('IA','responde que el jueves no puedo y propón el viernes','Lo siento, el jueves no puedo. ¿Movemos la reunión al viernes?'),
  ],
  modes_note='El diccionario «suena → se escribe» arregla nombres y términos para siempre: corriges «ene de a» una vez y desde entonces sale NDA.',
  how_h='Tres pasos, y el primero solo una vez',
  steps=[
    ('Activar el teclado','Ajustes → General → Teclado → Teclados → Scribla. Un minuto, y no vuelves a tocarlo.','main'),
    ('Tocar al escriba','En cualquier campo de texto: mensajería, correo, notas, búsqueda. El escriba con barba es el botón de grabar del teclado.','dictation'),
    ('Hablar','El texto aparece solo en el campo. ¿Entendió mal una palabra? La barra de corrección está al lado: cambias esa palabra, no toda la frase.','wordpanel'),
  ],
  mac_h='En el Mac, lo mismo con una tecla',
  mac_p='Un icono en la barra de menús. Mantén el ⌘ derecho y habla: el texto aparece donde está el cursor, en cualquier ventana. ⌘ + ⌥ responde a lo dicho; con / además traduce.',
  mac_facts=['La imagen está firmada y notarizada por Apple: se abre con doble clic','Apple Silicon e Intel, macOS 14 o posterior','Dictado y diccionario sin internet; respuesta y traducción, con el modelo'],
  mac_btn='Descargar para Mac', sha='SHA-256 de la imagen',
  mac_img_alt='La pastilla de Scribla en el Mac durante el dictado',
  privacy_h='El audio no sale a ninguna parte',
  privacy=[
    ('Reconocimiento en el equipo','Escucha tu teléfono o tu Mac. La grabación no sale de ahí, y ningún servidor tiene nada que guardar.'),
    ('Qué sale en los modos con IA','Solo el texto de la pregunta: a scribla.io y de ahí al modelo. Ni la pregunta ni la respuesta se guardan. Los modos se apagan en Ajustes.'),
    ('Sin cuenta ni anuncios','Sin registro, sin tarjeta, sin rastreadores. En este sitio tampoco hay grabación de sesiones.'),
  ],
  privacy_link='La política de privacidad completa', privacy_href='../privacy.html#en',
  dl_h='Descargar Scribla', dl_p='Gratis en los dos.',
  dl_ios_h='iPhone', dl_ios_meta='Versión 1.2 · iOS 17 o posterior',
  dl_mac_h='Mac', dl_mac_meta='Versión 1.0 · 24,7 MB · macOS 14 o posterior',
  faq_h='Preguntas', faq_pick=[0,1,4,5,8,12],
  contact_h='Escríbenos',
  footer_about='Scribla es dictado para iPhone y Mac. Lo hace un equipo pequeño.',
  f_links=[('../privacy.html#en','Política de privacidad'),('../support.html','Ayuda'),('#feedback','Escríbenos'),('#support','Apoyar')],
)

S['zh'] = dict(
  out='zh/index.html', pre='../', lang='zh-Hans', cur='中文', shots='en', demo='en',
  nav=[('#how','怎么用'),('#modes','四种模式'),('#mac','Mac 版'),('#privacy','隐私'),('#faq','常见问题')],
  top='下载', langlabel='语言',
  h1='说出口，字已在输入框里',
  lead='iPhone 与 Mac 上的语音输入：标点自动加好，「呃」自动去掉，还能算数、翻译、回答问题。免费，声音不离开设备。',
  ios='下载 iPhone 版', mac='下载 Mac 版',
  meta_mac='macOS 14 及以上 · Apple 芯片与 Intel · 25&nbsp;MB · 已由 Apple 签名',
  meta_ios='iOS 17 及以上 · 四种语言 · 无需注册',
  alt_h='Scribla 只在 iPhone 和 Mac 上运行', alt_p='用手机打开 App Store，或用相机对准二维码。',
  alt_mail='留下邮箱，有新消息时我们写信给你。', alt_btn='有消息通知我', alt_ph='you@example.com',
  badge='appstore-badge-zh-cn.svg', badge_alt='在 App Store 下载', qr_alt='二维码：App Store 上的 Scribla',
  sound_on='打开声音', video_alt='录屏：Scribla 键盘在「提醒事项」里把语音变成现成的文字',
  said='你说', got='输入框里',
  modes_h='四种模式，模式决定输入框里出现什么',
  modes_p='选好的模式会一直保持，直到你换掉它：不用说「算一下」或「翻译」。',
  modes=[
    ('文字','呃 阿依达 早上好 非常感谢','阿依达，早上好！非常感谢。'),
    ('算数','两千四百的百分之十五','360'),
    ('翻译','文件已收到，周五前回复','The documents have been received, we will reply by Friday.'),
    ('AI','回复说周四不行，建议周五','很抱歉，周四不方便。建议把会议改到周五。'),
  ],
  modes_note='「听起来像 → 写成」词典一次改好名字和术语：把「恩迪诶」纠正一次，以后永远是 NDA。',
  how_h='三步，第一步只做一次',
  steps=[
    ('启用键盘','设置 → 通用 → 键盘 → 键盘 → Scribla。一分钟，以后不用再管。','main'),
    ('点一下书吏','在任何输入框里：聊天、邮件、备忘录、搜索。键盘上那位留胡子的书吏就是录音键。','dictation'),
    ('开口说','文字自己落进输入框。听错了一个词？旁边就是修改栏：只改那个词，不用重说整句。','wordpanel'),
  ],
  mac_h='在 Mac 上，一个键做同样的事',
  mac_p='菜单栏里一个图标。按住右侧 ⌘ 说话，文字落在任何窗口的光标处。⌘ + ⌥ 是回答你说的话；再加 / 是翻译。',
  mac_facts=['映像已由 Apple 签名并公证：双击即可打开','Apple 芯片与 Intel，macOS 14 及以上','听写和词典离线可用；回答和翻译由模型完成'],
  mac_btn='下载 Mac 版', sha='映像的 SHA-256',
  mac_img_alt='听写时 Mac 上的 Scribla 悬浮条',
  privacy_h='声音不离开设备',
  privacy=[
    ('在设备上识别','听的是你的手机或 Mac。录音不会离开设备，服务器也没有东西可存。'),
    ('AI 模式下会发出什么','只有问题的文字：发到 scribla.io，再转给模型。问题和答案都不保存。这些模式可以在设置里关掉。'),
    ('没有账号，没有广告','无需注册，不要银行卡，没有跟踪器。本网站也没有会话录制。'),
  ],
  privacy_link='完整的隐私政策', privacy_href='../privacy.html#en',
  dl_h='下载 Scribla', dl_p='两个平台都免费。',
  dl_ios_h='iPhone', dl_ios_meta='版本 1.2 · iOS 17 及以上',
  dl_mac_h='Mac', dl_mac_meta='版本 1.0 · 24.7 MB · macOS 14 及以上',
  faq_h='常见问题', faq_pick=[0,1,4,5,8,12],
  contact_h='写信给我们',
  footer_about='Scribla 是 iPhone 与 Mac 上的语音输入，由一个小团队制作。',
  f_links=[('../privacy.html#en','隐私政策'),('../support.html','帮助'),('#feedback','写信给我们'),('#support','支持我们')],
)

LANGS = [('/', 'ru', 'RU'), ('/en/', 'en', 'EN'), ('/es/', 'es', 'ES'), ('/zh/', 'zh-Hans', '中文')]


def legacy(lang):
    return (LEGACY / f'{lang}.html').read_text()


def grab(src, start_pat, end='</section>'):
    i = src.index(start_pat)
    j = src.index(end, i) + len(end)
    return src[i:j]


def faq_items(src, picks):
    block = grab(src, '<section id="faq"')
    items = re.findall(r'<details>.*?</details>', block, re.S)
    return '\n'.join(items[i] for i in picks)


def head(src, lang):
    h = src[src.index('<head>'):src.index('</head>') + 7]
    # Ролик с пером больше не грузится; метрика и preload остаются.
    h = re.sub(r'<link rel="preload" href="[^"]*hero-paper.jpg" as="image">\n', '', h)
    return h


def render(d):
    src = legacy(d['lang'][:2])
    pre = d['pre']
    a = f'{pre}assets/'
    items = ''.join(
        f'\n        <a href="{p}" aria-current="page">{lab}</a>' if lab == d['cur']
        else f'\n        <a href="{p}" hreflang="{hl}">{lab}</a>' for p, hl, lab in LANGS)
    nav = ''.join(f'\n      <a href="{h}">{t}</a>' for h, t in d['nav'])
    modes = ''.join(f'''
      <li class="mode">
        <span class="mode-name">{n}</span>
        <span class="mode-said"><i>{d['said']}</i>{html.escape(s)}</span>
        <span class="mode-got"><i>{d['got']}</i>{html.escape(g)}</span>
      </li>''' for n, s, g in d['modes'])
    steps = ''.join(f'''
      <li class="step">
        <img src="{a}img/app/{img}-{d['shots']}.webp" alt="" width="720" height="1558" loading="lazy">
        <h3>{t}</h3>
        <p>{p}</p>
      </li>''' for t, p, img in d['steps'])
    mac_facts = ''.join(f'\n        <li>{f}</li>' for f in d['mac_facts'])
    privacy = ''.join(f'''
      <div class="fact">
        <h3>{t}</h3>
        <p>{p}</p>
      </div>''' for t, p in d['privacy'])
    flinks = ''.join(f'\n      <a href="{h}">{t}</a>' for h, t in d['f_links'])
    feedback = grab(src, '<section id="feedback"')
    support = grab(src, '<section id="support"')
    faq = faq_items(src, d['faq_pick'])
    # Плашка и карточка ответа — снимки стенда SCRIBLA_HUD_SHOT (HUDDemo.swift),
    # то есть настоящие окна приложения, а не рисунок. Тёмная тема — свои.
    def pic(name, cls, w, h, alt):
        return (f'<picture class="{cls}"><source srcset="{a}img/app/{name}-dark.webp" media="(prefers-color-scheme: dark)">'
                f'<img src="{a}img/app/{name}-light.webp" alt="{alt}" width="{w}" height="{h}" loading="lazy"></picture>')
    mac_img = (pic('mac-hud-rec', 'hud-rec', 620, 120, d['mac_img_alt']) +
               pic('mac-hud-answer', 'hud-answer', 1010, 320, ''))

    return f'''<!DOCTYPE html>
<html lang="{d['lang']}">
{head(src, d['lang'])}
<body>
<noscript><div><img src="https://mc.yandex.ru/watch/111532968" style="position:absolute; left:-9999px;" alt=""></div></noscript>

<a class="sr skip" href="#main">{ {'ru':'К содержимому','en':'Skip to content','es':'Ir al contenido','zh-Hans':'跳到正文'}[d['lang']] }</a>

<!-- Страница собрана tools/build-pages.py 22 августа 2026: один макет
     на четыре языка. Править разметку — там, не здесь. -->
<header class="top">
  <div class="wrap">
    <a class="brand" href="{'/' if not pre else '/' + d['out'].split('/')[0] + '/'}">
      <img class="mark" src="{a}brand/scribla-dyak-writing.svg" alt="" width="32" height="32">
      <picture>
        <source srcset="{a}brand/scribla-wordmark-white.svg" media="(prefers-color-scheme: dark)">
        <img class="word" src="{a}brand/scribla-wordmark.svg" alt="Scribla">
      </picture>
    </a>
    <nav class="nav" aria-label="{d['nav'][0][1]}">{nav}
    </nav>
    <a class="btn btn-primary top-cta" href="#download" data-top-cta>{d['top']}</a>
    <details class="lang-menu">
      <summary aria-label="{d['langlabel']}">{d['cur']}</summary>
      <div class="lang">{items}
      </div>
    </details>
  </div>
</header>

<main id="main">

<section class="hero">
  <div class="wrap hero-grid">
    <div class="hero-text">
      <h1>{d['h1']}</h1>
      <p class="lead">{d['lead']}</p>
      <div class="hero-cta">
        <a class="btn btn-primary" data-dev="ios" href="{STORE}">{d['ios']}</a>
        <a class="btn btn-ghost" data-dev="mac" href="{DMG}">{d['mac']}</a>
      </div>
      <p class="hero-meta" data-dev="mac">{d['meta_mac']}</p>
      <p class="hero-meta hero-meta-ios" data-dev="ios">{d['meta_ios']}</p>
      <div class="hero-alt" data-dev="other" hidden>
        <p class="hero-alt-h">{d['alt_h']}</p>
        <p class="hero-alt-p">{d['alt_p']}</p>
        <div class="hero-alt-row">
          <a href="{STORE}"><img src="{a}img/{d['badge']}" alt="{d['badge_alt']}" width="120" height="40"></a>
          <img class="hero-qr" src="{a}img/appstore-qr.svg" alt="{d['qr_alt']}" width="124" height="124">
        </div>
        <form class="hero-alt-form" data-notify novalidate>
          <p class="hero-alt-p"><label for="alt-email">{d['alt_mail']}</label></p>
          <div class="hero-alt-fields">
            <input id="alt-email" name="email" type="email" autocomplete="email" placeholder="{d['alt_ph']}" required>
            <button class="btn btn-primary" type="submit">{d['alt_btn']}</button>
          </div>
          <p class="msg" data-notify-msg aria-live="polite"></p>
        </form>
      </div>
    </div>
    <!-- Не макет и не иллюстрация: запись экрана настоящей клавиатуры
         в «Напоминаниях», та же, что в App Store. Звук включается кнопкой:
         браузер не даст запустить его сам. -->
    <figure class="hero-demo">
      <div class="phone">
        <video data-film poster="{a}img/app/demo-{d['demo']}.jpg" width="886" height="1920"
               muted playsinline loop preload="metadata" aria-label="{d['video_alt']}">
          <source src="{a}video/demo-{d['demo']}.mp4" type="video/mp4">
        </video>
      </div>
      <button class="film-sound" type="button" data-film-sound aria-pressed="false">
        <svg viewBox="0 0 16 16" aria-hidden="true" width="14" height="14">
          <path d="M7 2.5 3.8 5.2H1.6v5.6h2.2L7 13.5z"/>
          <path d="M10.2 5.4a3.4 3.4 0 0 1 0 5.2" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>
          <path d="M12.3 3.4a6.2 6.2 0 0 1 0 9.2" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" data-film-wave/>
        </svg>
        <span data-film-label>{d['sound_on']}</span>
      </button>
    </figure>
  </div>
</section>

<section id="modes">
  <div class="wrap">
    <h2>{d['modes_h']}</h2>
    <p class="lead">{d['modes_p']}</p>
    <ul class="modes">{modes}
    </ul>
    <p class="note">{d['modes_note']}</p>
  </div>
</section>

<section id="how">
  <div class="wrap">
    <h2>{d['how_h']}</h2>
    <ol class="steps">{steps}
    </ol>
  </div>
</section>

<section id="mac">
  <div class="wrap mac-grid">
    <div>
      <h2>{d['mac_h']}</h2>
      <p class="lead">{d['mac_p']}</p>
      <ul class="facts">{mac_facts}
      </ul>
      <a class="btn btn-primary" href="{DMG}">{d['mac_btn']}</a>
      <p class="sha">{d['sha']}: <code>{SHA}</code></p>
    </div>
    <div class="mac-visual">{mac_img}</div>
  </div>
</section>

<section id="privacy">
  <div class="wrap">
    <h2>{d['privacy_h']}</h2>
    <div class="privacy-grid">{privacy}
    </div>
    <p class="note"><a href="{d['privacy_href']}">{d['privacy_link']}</a></p>
  </div>
</section>

<section id="download" class="download">
  <div class="wrap">
    <h2>{d['dl_h']}</h2>
    <p class="lead">{d['dl_p']}</p>
    <div class="dl-grid">
      <div class="dl-card">
        <h3>{d['dl_ios_h']}</h3>
        <a class="dl-badge" href="{STORE}"><img src="{a}img/{d['badge']}" alt="{d['badge_alt']}" width="156" height="52"></a>
        <p class="dl-meta">{d['dl_ios_meta']}</p>
      </div>
      <div class="dl-card">
        <h3>{d['dl_mac_h']}</h3>
        <a class="btn btn-primary" href="{DMG}">{d['mac_btn']}</a>
        <p class="dl-meta">{d['dl_mac_meta']}</p>
      </div>
    </div>
  </div>
</section>

<section id="faq">
  <div class="wrap">
    <h2>{d['faq_h']}</h2>
    <div class="faq">
{faq}
    </div>
  </div>
</section>

<div class="contact">
{feedback}
{support}
</div>

</main>

<footer>
  <div class="wrap">
    <nav class="f-links" aria-label="Scribla">{flinks}
    </nav>
    <p class="f-about">{d['footer_about']} © 2026 <a href="mailto:dev@scribla.io">dev@scribla.io</a></p>
  </div>
</footer>
<script src="{a}site.js"></script>
</body>
</html>
'''


if __name__ == '__main__':
    for d in S.values():
        out = ROOT / d['out']
        out.write_text(render(d))
        print(out.relative_to(ROOT), len(out.read_text()))
