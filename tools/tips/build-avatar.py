#!/usr/bin/env python3
"""Аватар Дьяка С ПЕРОМ для профиля Gumroad.

Готовый scribla-avatar.svg не годится: он приближен так, что перо
осталось за кадром, а просят именно с пером.

Рисуется без Chrome — он в этой сессии виснет. Фигура берётся из
рендера Quick Look (`qlmanage -t -s 2048`), а тот кладёт её на БЕЛОЕ.
Выбивать белое по цвету нельзя: перо у Дьяка пергаментное (#F5EDE3),
до белого ему 10+18+28 = 56 по сумме каналов, и порог, достаточный
для сглаженных краёв, съел бы перо целиком. Поэтому заливка от границы
(связность), а не по цвету: перо лежит внутри и её не касается.
"""
from PIL import Image, ImageDraw
import math

SRC = "scribla-dyak-alpha.svg.png"     # 2048, фигура на белом
S = 1024                                # сторона аватара
TERRA_TOP, TERRA_BOT = (0xC4, 0x62, 0x2C), (0x8C, 0x3F, 0x1B)
SENTINEL = (255, 0, 255)


def cut_out():
    """Белый фон → прозрачность, заливкой от четырёх углов."""
    rgb = Image.open(SRC).convert("RGB")
    for xy in [(0, 0), (rgb.width - 1, 0), (0, rgb.height - 1), (rgb.width - 1, rgb.height - 1)]:
        ImageDraw.floodfill(rgb, xy, SENTINEL, thresh=40)
    out = rgb.convert("RGBA")
    px = out.load()
    for y in range(out.height):
        for x in range(out.width):
            if px[x, y][:3] == SENTINEL:
                px[x, y] = (0, 0, 0, 0)
    return out


def ink_points(im, step=3):
    px = im.load()
    return [(x, y) for y in range(0, im.height, step)
            for x in range(0, im.width, step) if px[x, y][3] > 128]


def best_centre(pts, cx):
    """Центр по вертикали, при котором фигура влезает в наименьший круг.

    Брать середину рамки нельзя: у Дьяка перо свисает вправо и вниз,
    рамка из-за него врёт, и круг выходит крупнее необходимого —
    а значит голова в аватаре мельче, чем могла бы быть.
    """
    ys = [p[1] for p in pts]
    lo, hi = min(ys), max(ys)
    best = None
    for _ in range(40):                       # троичный поиск
        m1 = lo + (hi - lo) / 3
        m2 = hi - (hi - lo) / 3
        r1 = max(math.hypot(x - cx, y - m1) for x, y in pts)
        r2 = max(math.hypot(x - cx, y - m2) for x, y in pts)
        if r1 < r2:
            hi = m2; best = (m1, r1)
        else:
            lo = m1; best = (m2, r2)
    return best


def background():
    g = Image.new("RGB", (1, S))
    p = g.load()
    for y in range(S):
        t = y / (S - 1)
        p[0, y] = tuple(round(a + (b - a) * t) for a, b in zip(TERRA_TOP, TERRA_BOT))
    return g.resize((S, S), Image.BICUBIC).convert("RGBA")


def build(fig, pts, cx, cy, radius, fill, name):
    """fill — доля радиуса аватара, которую занимает фигура."""
    scale = (S / 2 * fill) / radius
    w, h = round(fig.width * scale), round(fig.height * scale)
    small = fig.resize((w, h), Image.LANCZOS)
    canvas = background()
    canvas.paste(small, (round(S / 2 - cx * scale), round(S / 2 - cy * scale)), small)
    canvas.convert("RGB").save(name)
    return scale


def clipped(name, margin=1.0):
    """Сколько краски вылезло бы за круглую обрезку. Проверка, а не вера."""
    im = Image.open(name).convert("RGB")
    bg = Image.open(name).convert("RGB")
    px = im.load()
    r = S / 2 * margin
    out = 0
    for y in range(0, S, 2):
        for x in range(0, S, 2):
            if math.hypot(x - S / 2, y - S / 2) > r:
                c = px[x, y]
                # не терракота — значит краска фигуры
                if not (0x80 <= c[0] <= 0xD0 and 0x30 <= c[1] <= 0x70 and 0x10 <= c[2] <= 0x40):
                    out += 1
    return out


if __name__ == "__main__":
    fig = cut_out()
    fig.save("dyak-cut.png")
    box = fig.getbbox()
    print("рамка краски", box)
    pts = ink_points(fig)
    cx = (box[0] + box[2]) / 2
    cy, radius = best_centre(pts, cx)
    print(f"центр ({cx:.0f}, {cy:.0f}), радиус краски {radius:.0f}")

    for fill, name in [(0.86, "avatar-a.png"), (0.97, "avatar-b.png")]:
        s = build(fig, pts, cx, cy, radius, fill, name)
        print(f"{name}: масштаб {s:.3f}, за кругом краски {clipped(name)} точек")
