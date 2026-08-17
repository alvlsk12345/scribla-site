#!/usr/bin/env python3
"""Иконка сайта в корень: favicon.ico и favicon.svg.

Зачем в корень, если иконка и так объявлена в <head>. Яндекс требует
именно этого: файл с именем `favicon` в корневом каталоге сайта. У нас
он был объявлен из глубины (`assets/brand/scribla-appicon.svg`), а по
корневому адресу `/favicon.ico` сервер отвечал 404 — то есть у робота,
который ходит туда по умолчанию, иконки не было вовсе. В выдаче на её
месте стоял серый глобус.

`.ico` кладём рядом с `.svg` не для Яндекса, а для всех остальных:
за `/favicon.ico` браузеры и чужие роботы ходят сами, не читая разметку.

Размеры 16/32/48 — те, что перечислены у Яндекса, плюс 48 для вкладок
на плотных экранах. Скругление то же, что у SVG: 229 из 1024, то есть
22,4% стороны.

Запуск:  python3 tools/build-favicon.py
"""
import pathlib
from PIL import Image, ImageDraw

ROOT = pathlib.Path(__file__).resolve().parent.parent
SRC = ROOT / "assets/brand/AppIcon-1024.png"
SVG_SRC = ROOT / "assets/brand/scribla-appicon.svg"
SIZES = [16, 32, 48]
RADIUS = 229 / 1024


def rounded(img: Image.Image, size: int) -> Image.Image:
    """Уменьшаем и срезаем углы тем же радиусом, что в SVG.

    Маску рисуем в четыре раза крупнее и потом ужимаем: прямое
    рисование скругления на 16 px даёт рваный край, антиалиасинга
    у ImageDraw нет.
    """
    big = size * 4
    small = img.resize((big, big), Image.LANCZOS)
    mask = Image.new("L", (big, big), 0)
    ImageDraw.Draw(mask).rounded_rectangle(
        (0, 0, big - 1, big - 1), radius=int(big * RADIUS), fill=255)
    out = Image.new("RGBA", (big, big), (0, 0, 0, 0))
    out.paste(small, (0, 0), mask)
    return out.resize((size, size), Image.LANCZOS)


def main() -> None:
    src = Image.open(SRC).convert("RGBA")
    frames = [rounded(src, s) for s in SIZES]
    ico = ROOT / "favicon.ico"
    frames[-1].save(ico, format="ICO",
                    sizes=[(s, s) for s in SIZES], append_images=frames[:-1])

    svg = ROOT / "favicon.svg"
    svg.write_bytes(SVG_SRC.read_bytes())

    print(f"{ico.relative_to(ROOT)}  {ico.stat().st_size} Б  "
          f"({', '.join(f'{s}×{s}' for s in SIZES)})")
    print(f"{svg.relative_to(ROOT)}  {svg.stat().st_size} Б  копия "
          f"{SVG_SRC.relative_to(ROOT)}")


if __name__ == "__main__":
    main()
