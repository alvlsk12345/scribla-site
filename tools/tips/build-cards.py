#!/usr/bin/env python3
"""Обложка и значок карточки товара Gumroad.

Требования площадки: обложка горизонтальная, не меньше 1280×720;
значок квадратный, не меньше 600×600. Берём с запасом — 1600×900
и 1000×1000, Gumroad уменьшит сам.

Показываем сам товар: три обоины в телефонах. Текста нет вовсе —
шрифты сайта лежат в woff2, PIL их не читает, а словесный знак
подставляется картинкой, отрисованной из SVG.
"""
from PIL import Image, ImageDraw, ImageFilter
import build as B  # градиент, зерно, виньетка — общие с обоями

PHONES = ["terracotta", "parchment", "ink"]


def phone(wall, h):
    """Обоина в скруглённой рамке — так видно, что это именно обои."""
    w = round(h * 1320 / 2868)
    r = round(w * 0.135)
    img = Image.open(f"out/scribla-wallpaper-{wall}.png").resize((w, h), Image.LANCZOS)

    s = 4  # маска рисуется крупнее и уменьшается, иначе край рваный
    m = Image.new("L", (w * s, h * s), 0)
    ImageDraw.Draw(m).rounded_rectangle([0, 0, w * s - 1, h * s - 1], radius=r * s, fill=255)
    m = m.resize((w, h), Image.LANCZOS)

    out = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    out.paste(img, (0, 0), m)

    # Тонкая тёмная кромка: на пергаментной обоине светлая рамка
    # на светлом фоне иначе растворяется.
    edge = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    ImageDraw.Draw(edge).rounded_rectangle([1, 1, w - 2, h - 2], radius=r,
                                           outline=(36, 26, 21, 70), width=3)
    return Image.alpha_composite(out, edge)


def shadow(canvas, box, blur, alpha):
    x, y, w, h = box
    sh = Image.new("RGBA", canvas.size, (0, 0, 0, 0))
    ImageDraw.Draw(sh).rounded_rectangle([x, y + blur // 2, x + w, y + h + blur // 2],
                                         radius=round(w * 0.135), fill=(60, 35, 20, alpha))
    return Image.alpha_composite(canvas, sh.filter(ImageFilter.GaussianBlur(blur)))


def background(w, h, top, bot, grain_strength):
    """Тот же приём, что у обоев, только под другой размер холста."""
    old = (B.W, B.H)
    B.W, B.H = w, h
    try:
        return B.apply_grain(B.gradient(top, bot), grain_strength)
    finally:
        B.W, B.H = old


def cover():
    W, H = 1600, 900
    canvas = background(W, H, B.PARCH_TOP, B.PARCH_BOT, 12.0).convert("RGBA")

    ph = round(H * 0.78)
    shots = [phone(n, ph) for n in PHONES]
    pw = shots[0].width
    gap = round(pw * 0.14)
    total = pw * 3 + gap * 2
    x0 = (W - total) // 2
    y = (H - ph) // 2

    for i, s in enumerate(shots):
        x = x0 + i * (pw + gap)
        canvas = shadow(canvas, (x, y, pw, ph), 26, 70)
        canvas.paste(s, (x, y), s)

    canvas.convert("RGB").save("out/gumroad-cover.png")
    return (W, H)


def thumb():
    """Один телефон, не веер.

    Веером пробовал — на 600×600 в списке товаров три телефона внахлёст
    превращаются в кашу, а терракотовая обоина на терракотовом фоне
    пропадает вовсе. Берём пергаментную: она единственная, что читается
    и на светлом, и на тёмном.
    """
    S = 1000
    canvas = background(S, S, B.TERRA_TOP, B.TERRA_BOT, 9.0).convert("RGBA")

    ph = round(S * 0.80)
    s = phone("parchment", ph)
    x, y = (S - s.width) // 2, (S - ph) // 2
    canvas = shadow(canvas, (x, y, s.width, ph), 26, 95)
    canvas.paste(s, (x, y), s)

    canvas.convert("RGB").save("out/gumroad-thumbnail.png")
    return (S, S)


if __name__ == "__main__":
    import os
    print("обложка ", cover(), f"{os.path.getsize('out/gumroad-cover.png')//1024} КБ")
    print("значок  ", thumb(), f"{os.path.getsize('out/gumroad-thumbnail.png')//1024} КБ")
