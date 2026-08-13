#!/usr/bin/env python3
"""Собирает набор обоев с Дьяком для отдачи за перевод.

Дьяк берётся из scribla-dyak-alpha.svg — прозрачного. Помнить про его
устройство: перо пергаментное (#F5EDE3), поэтому на светлом фоне оно
пропадает. Отсюда правило: на пергаменте и на чернилах Дьяк живёт внутри
терракотового круга, и только на сплошной терракоте — сам по себе.

Размер один — 1320×2868 (iPhone 16 Pro Max). Соотношение сторон у всех
нынешних iPhone сходится в пределах 0,3% (2868/1320 = 2,173,
2796/1290 = 2,167, 2556/1179 = 2,168), поэтому iOS уменьшает без обреза.
"""
import random
from PIL import Image, ImageDraw, ImageFilter

W, H = 1320, 2868
DYAK = "dyak.png"          # 2048×2048, прозрачный
OUT = "out"

TERRA_TOP, TERRA_BOT = (0xC4, 0x62, 0x2C), (0x8C, 0x3F, 0x1B)
PARCH_TOP, PARCH_BOT = (0xF7, 0xF1, 0xE9), (0xE6, 0xD6, 0xC0)
INK_TOP,   INK_BOT   = (0x24, 0x1A, 0x15), (0x0E, 0x0A, 0x08)
TERRA = (0xB8, 0x5A, 0x28)


def gradient(top, bot):
    """Вертикальный градиент во всю высоту."""
    g = Image.new("RGB", (1, H))
    px = g.load()
    for y in range(H):
        t = y / (H - 1)
        px[0, y] = tuple(round(a + (b - a) * t) for a, b in zip(top, bot))
    return g.resize((W, H), Image.BICUBIC)


def grain(strength, seed=20260813):
    """Своё зерно бумаги: шум, размытый до крупности волокна.

    Готовый hero-paper.jpg не годится — в него уже запечён градиент
    первого экрана, он бы спорил с фоном обоев.
    """
    rnd = random.Random(seed)
    small = Image.new("L", (W // 2, H // 2))
    small.putdata([rnd.gauss(128, strength) for _ in range(small.width * small.height)])
    return small.resize((W, H), Image.BICUBIC).filter(ImageFilter.GaussianBlur(0.6))


def apply_grain(img, strength=10.0):
    n = grain(strength)
    return Image.blend(img, Image.composite(
        Image.new("RGB", (W, H), (255, 255, 255)), img,
        n.point(lambda v: max(0, min(255, (v - 128) * 2 + 128)))), 0.045)


def vignette(img, power=0.55):
    """Мягкое затемнение к краям — держит взгляд на фигуре."""
    m = Image.new("L", (W, H), 0)
    d = ImageDraw.Draw(m)
    pad = int(W * 0.16)
    d.ellipse([-pad, int(H * 0.10), W + pad, int(H * 1.02)], fill=255)
    m = m.filter(ImageFilter.GaussianBlur(W * 0.30))
    dark = Image.new("RGB", (W, H), (0, 0, 0))
    return Image.composite(img, Image.blend(img, dark, power), m)


def place(canvas, dyak, height_frac, centre_frac):
    """Кладёт Дьяка так, чтобы его РИСУНОК (а не холст SVG) занял
    height_frac высоты, а середина рисунка встала на centre_frac.
    Меряется по настоящей рамке непрозрачного, потому что в квадрате
    1024×1024 у фигуры свои поля."""
    box = dyak.getbbox()
    art = dyak.crop(box)
    target_h = int(H * height_frac)
    scale = target_h / art.height
    art = art.resize((round(art.width * scale), target_h), Image.LANCZOS)
    x = (W - art.width) // 2
    y = int(H * centre_frac) - art.height // 2
    canvas.paste(art, (x, y), art)
    return canvas


def disc(canvas, radius_frac, centre_frac, colour, glow=0):
    """Терракотовый круг под фигурой. На пергаменте и чернилах без него
    нельзя: перо у Дьяка пергаментное и на светлом фоне исчезает,
    а шапка (#241A15) тонет в чернильном."""
    r = int(W * radius_frac)
    cx, cy = W // 2, int(H * centre_frac)
    if glow:
        g = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        ImageDraw.Draw(g).ellipse([cx - r - glow, cy - r - glow,
                                   cx + r + glow, cy + r + glow],
                                  fill=colour + (64,))
        canvas.paste(Image.alpha_composite(canvas.convert("RGBA"),
                     g.filter(ImageFilter.GaussianBlur(glow * 1.1))).convert("RGB"), (0, 0))
    # Круг рисуется вчетверо крупнее и уменьшается — иначе край рваный.
    s = 4
    m = Image.new("L", (W * s, H * s), 0)
    ImageDraw.Draw(m).ellipse([(cx - r) * s, (cy - r) * s, (cx + r) * s, (cy + r) * s], fill=255)
    m = m.resize((W, H), Image.LANCZOS)
    canvas.paste(Image.new("RGB", (W, H), colour), (0, 0), m)
    return canvas


def main():
    import os
    os.makedirs(OUT, exist_ok=True)
    dyak = Image.open(DYAK).convert("RGBA")

    # 1. Терракота — Дьяк сам по себе, во весь рост.
    a = vignette(apply_grain(gradient(TERRA_TOP, TERRA_BOT)), 0.55)
    a = place(a, dyak, 0.355, 0.605)
    a.save(f"{OUT}/scribla-wallpaper-terracotta.png")

    # 2. Пергамент — Дьяк в круге, круг и есть знак приложения.
    b = apply_grain(gradient(PARCH_TOP, PARCH_BOT), 12.0)
    b = disc(b, 0.36, 0.60, TERRA)
    b = place(b, dyak, 0.30, 0.605)
    b.save(f"{OUT}/scribla-wallpaper-parchment.png")

    # 3. Чернила — тот же круг плюс тёплое свечение вокруг.
    c = apply_grain(gradient(INK_TOP, INK_BOT), 8.0)
    c = disc(c, 0.36, 0.60, TERRA, glow=int(W * 0.10))
    c = place(c, dyak, 0.30, 0.605)
    c.save(f"{OUT}/scribla-wallpaper-ink.png")

    for f in sorted(os.listdir(OUT)):
        p = f"{OUT}/{f}"
        print(f"{f:42s} {Image.open(p).size}  {os.path.getsize(p)//1024:4d} КБ")


if __name__ == "__main__":
    main()
