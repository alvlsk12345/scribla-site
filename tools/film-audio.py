# -*- coding: utf-8 -*-
"""
Звук для роликов Scribla: фоновая музыка и перо по бумаге.

Всё синтезируется здесь, с нуля: на сайте не должно быть чужих сэмплов
с их лицензиями. Музыка — войлочное пианино поверх тёплой подушки,
перо — шумовая полоса около 2–5 кГц с дрожащей огибающей нажима.

Считает чистый Python, поэтому всё, что можно, идёт таблицей:
одна периодическая волна вместо пяти вызовов sin на отсчёт.
"""
import math
import os
import random
import struct
import wave

SR = 48000
DUR = 8.70            # чуть длиннее ролика (8.633), лишнее обрежет ffmpeg
N = int(SR * DUR)
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'build')

TBL = 8192
TWO_PI = 6.283185307179586


def make_table(partials):
    """Один период суммы гармоник — потом читается как волновая таблица."""
    t = [0.0] * TBL
    for mult, amp in partials:
        for i in range(TBL):
            t[i] += amp * math.sin(TWO_PI * mult * i / TBL)
    return t


SINE = make_table([(1.0, 1.0)])
# Подушка: без резких верхов, чтобы не спорить с голосом.
PADW = make_table([(1.0, 1.0), (2.0, 0.30), (3.0, 0.10), (4.0, 0.045), (6.0, 0.02)])


def note(name):
    """Частота по имени вида 'A4', 'F#3', 'Bb2'."""
    base = {'C': 0, 'D': 2, 'E': 4, 'F': 5, 'G': 7, 'A': 9, 'B': 11}
    n = base[name[0]]
    i = 1
    while i < len(name) and name[i] in '#b':
        n += 1 if name[i] == '#' else -1
        i += 1
    midi = 12 * (int(name[i:]) + 1) + n
    return 440.0 * 2 ** ((midi - 69) / 12.0)


def smooth(x):
    return x * x * (3 - 2 * x)


# ─────────────────────────────────────────────── подушка (пад)

def pad(buf, freq, t0, t1, amp, rnd, fade=0.9):
    i0, i1 = int(t0 * SR), min(int(t1 * SR), N)
    if i1 <= i0:
        return
    ph = rnd.random() * TBL
    inc = freq * TBL / SR
    lfo_ph = rnd.random() * TBL
    lfo_inc = (0.13 + rnd.random() * 0.17) * TBL / SR
    fin = max(1, int(fade * SR))
    fout = max(1, int(min(1.4, (t1 - t0) * 0.45) * SR))
    for i in range(i0, i1):
        k = i - i0
        env = amp
        if k < fin:
            env *= smooth(k / fin)
        rem = i1 - i
        if rem < fout:
            env *= smooth(rem / fout)
        env *= 0.82 + 0.18 * SINE[int(lfo_ph) & (TBL - 1)]
        buf[i] += PADW[int(ph) & (TBL - 1)] * env
        ph += inc
        lfo_ph += lfo_inc


# ────────────────────────────────────────── войлочное пианино

def pluck_mono(freq, amp, tau, rnd):
    """Нота молоточком: обертоны затухают быстрее основного тона."""
    length = min(int(tau * 2.6 * SR), N)
    buf = [0.0] * length
    parts = [(1.0, 1.0, 1.00), (2.0, 0.38, 0.70), (3.0, 0.15, 0.54),
             (4.0, 0.07, 0.42), (5.0, 0.03, 0.33)]
    for mult, a, dk in parts:
        # слабая ингармоничность — от неё звук перестаёт быть «электронным»
        f = freq * mult * (1 + 0.00035 * mult * mult)
        inc = f * TBL / SR
        ph = 0.0
        env = a * amp
        dec = math.exp(-1.0 / (tau * dk * SR))
        for i in range(length):
            buf[i] += SINE[int(ph) & (TBL - 1)] * env
            ph += inc
            env *= dec
    # войлок молоточка
    atk = int(0.010 * SR)
    hp = 0.0
    for i in range(atk):
        hp = 0.6 * hp + 0.4 * rnd.uniform(-1, 1)
        buf[i] += hp * amp * 0.20 * math.exp(-i / (0.0035 * SR))
    # снять щелчок в самом начале
    ramp = int(0.004 * SR)
    for i in range(ramp):
        buf[i] *= i / ramp
    return buf


def add_at(dst, src, t0, gain):
    i0 = int(t0 * SR)
    for i in range(len(src)):
        j = i0 + i
        if j >= N:
            break
        dst[j] += src[i] * gain


# ───────────────────────────────────────────────────── реверб

def reverb(buf, wet, delays, seed):
    """Шрёдер: четыре гребёнки с демпфированием и два фазовращателя."""
    rnd = random.Random(seed)
    out = [0.0] * N
    for delay, g in delays:
        line = [0.0] * delay
        idx = 0
        lp = 0.0
        for i in range(N):
            y = line[idx]
            out[i] += y * 0.25
            lp = 0.70 * lp + 0.30 * y
            line[idx] = buf[i] + lp * g
            idx += 1
            if idx == delay:
                idx = 0
    for delay, g in ((347 + rnd.randint(0, 9), 0.7), (113 + rnd.randint(0, 5), 0.7)):
        line = [0.0] * delay
        idx = 0
        for i in range(N):
            y = line[idx]
            x = out[i]
            line[idx] = x + y * g
            out[i] = y - x * g
            idx += 1
            if idx == delay:
                idx = 0
    for i in range(N):
        buf[i] = buf[i] * (1 - wet) + out[i] * wet


# ──────────────────────────────────────────────────── музыка

def build_music():
    rnd = random.Random(20260811)
    base = [0.0] * N

    # Dmaj9 → Bm7 → Gmaj9 → возврат к D. Терции в подушке нет намеренно:
    # так теплее и меньше спорит с голосом.
    chords = [
        (0.00, 3.45, ['D3', 'A3', 'C#4', 'E4'], 0.105),
        (3.20, 5.95, ['B2', 'F#3', 'A3', 'D4'], 0.105),
        (5.70, 8.70, ['G2', 'D3', 'F#3', 'B3'], 0.115),
        (7.15, 8.70, ['D3', 'E3', 'A3', 'D4'], 0.080),
    ]
    for t0, t1, names, amp in chords:
        for nm in names:
            pad(base, note(nm), t0, t1, amp, rnd)

    for t0, t1, nm in ((0.0, 3.5, 'D2'), (3.2, 6.0, 'B1'), (5.7, 8.7, 'G1')):
        pad(base, note(nm), t0, t1, 0.075, rnd, fade=1.1)

    L = base[:]
    R = base[:]

    # Мелодия: восемь нот на девять секунд. Редко — под ней говорит голос,
    # а в кадре и без того много движения.
    tune = [
        (0.45, 'F#4', 0.26, 1.7),
        (1.75, 'C#5', 0.20, 1.5),
        (2.70, 'A4',  0.22, 1.6),
        (3.55, 'D5',  0.24, 1.5),
        (4.60, 'B4',  0.19, 1.6),
        (5.35, 'F#4', 0.21, 1.8),
        (6.05, 'A4',  0.27, 2.0),      # сюда приходит слово Scribla
        (7.20, 'D5',  0.23, 2.2),
    ]
    for t0, nm, amp, tau in tune:
        src = pluck_mono(note(nm), amp, tau, rnd)
        p = 0.42 + rnd.random() * 0.16          # лёгкая панорама по нотам
        add_at(L, src, t0, 1.0 - p * 0.5)
        add_at(R, src, t0, 0.55 + p * 0.5)

    # Разные линии задержки слева и справа — отсюда берётся ширина.
    reverb(L, 0.34, [(1187, 0.76), (1291, 0.746), (1427, 0.732), (1571, 0.718)], 1)
    reverb(R, 0.34, [(1213, 0.76), (1319, 0.746), (1451, 0.732), (1601, 0.718)], 2)

    for i in range(N):
        t = i / SR
        e = 1.0
        if t < 1.0:
            e *= smooth(t)
        if t > 8.05:
            e *= smooth(max(0.0, (8.70 - t) / 0.65))
        # тише там, где говорит голос; свободнее на финальном плане
        e *= 0.86 if t < 3.1 else (0.93 if t < 5.6 else 1.12)
        L[i] *= e
        R[i] *= e
    return L, R


# ───────────────────────────────────────── перо по бумаге

def build_pen(strokes, seed):
    """strokes: [(начало, конец, ставить ли точку в конце)] — по раскадровке."""
    rnd = random.Random(seed)
    L = [0.0] * N
    R = [0.0] * N
    # резонатор около 4 кГц: с ним перо скрипит, без него просто шипит
    w0 = TWO_PI * 4000 / SR
    r = 0.985
    b1, b2 = 2 * r * math.cos(w0), r * r

    for t0, t1, dot in strokes:
        i0, i1 = int(t0 * SR), min(int(t1 * SR), N)
        if i1 <= i0:
            continue
        step = int(SR / 90)                     # дрожание нажима
        walk = []
        cur = rnd.uniform(0.55, 0.9)
        for _ in range((i1 - i0) // step + 2):
            cur = min(1.0, max(0.30, cur + rnd.uniform(-0.28, 0.28)))
            walk.append(cur)
        lifts = []                              # отрывы пера между буквами
        t = t0 + rnd.uniform(0.25, 0.5)
        while t < t1 - 0.12:
            lifts.append((t, t + rnd.uniform(0.025, 0.055)))
            t += rnd.uniform(0.22, 0.5)

        hp_in = hp_out = 0.0
        lp1 = lp2 = 0.0
        y1 = y2 = 0.0
        body = 0.0
        fade = int(0.018 * SR)
        wob_ph = rnd.random() * TBL
        wob_inc = (3.1 + rnd.random() * 1.4) * TBL / SR
        li = 0
        for i in range(i0, i1):
            k = i - i0
            x = rnd.uniform(-1.0, 1.0)

            hp = 0.79 * (hp_out + x - hp_in)    # выше ~2 кГц
            hp_in, hp_out = x, hp
            lp1 += 0.42 * (hp - lp1)            # ниже ~4 кГц
            lp2 += 0.42 * (lp1 - lp2)

            y = b1 * y1 - b2 * y2 + 0.03 * hp   # резонанс 4 кГц
            y2, y1 = y1, y
            body += 0.035 * (x - body)          # нажим на стол

            s = lp2 * 0.95 + y * 0.35 + body * 0.16

            kk = k // step
            w0v = walk[kk]
            w1v = walk[min(kk + 1, len(walk) - 1)]
            env = w0v + (w1v - w0v) * ((k % step) / step)
            env *= 0.72 + 0.28 * SINE[int(wob_ph) & (TBL - 1)]
            wob_ph += wob_inc
            tt = i / SR
            while li < len(lifts) and tt > lifts[li][1]:
                li += 1
            if li < len(lifts) and lifts[li][0] <= tt:
                env *= 0.12
            if k < fade:
                env *= k / fade
            rem = i1 - i
            if rem < fade:
                env *= rem / fade

            v = s * env
            L[i] += v * 0.94
            R[i] += v

        if dot:                                  # точка знака: короткий удар
            j0 = min(i1 + int(0.02 * SR), N - 1)
            dl = int(0.05 * SR)
            for j in range(j0, min(j0 + dl, N)):
                e = math.exp(-(j - j0) / (0.008 * SR))
                v = rnd.uniform(-1, 1) * e * 0.45
                L[j] += v
                R[j] += v * 0.9
    return L, R


# ──────────────────────────────────────────────────── запись

def write_wav(path, L, R, target_peak):
    pk = max(max(abs(v) for v in L), max(abs(v) for v in R)) or 1.0
    g = target_peak / pk
    rms = math.sqrt(sum((v * g) ** 2 for v in L) / N) or 1e-9
    frames = bytearray()
    for i in range(N):
        l = int(max(-1.0, min(1.0, L[i] * g)) * 32767)
        r = int(max(-1.0, min(1.0, R[i] * g)) * 32767)
        frames += struct.pack('<hh', l, r)
    with wave.open(path, 'wb') as w:
        w.setnchannels(2)
        w.setsampwidth(2)
        w.setframerate(SR)
        w.writeframes(bytes(frames))
    print('%-10s пик %5.1f dBFS   RMS %5.1f dBFS' %
          (os.path.basename(path), 20 * math.log10(target_peak), 20 * math.log10(rms)))


if __name__ == '__main__':
    os.makedirs(OUT, exist_ok=True)

    print('музыка…')
    ml, mr = build_music()
    write_wav(os.path.join(OUT, 'music.wav'), ml, mr, 0.72)

    # Когда перо действительно пишет — снято с раскадровки роликов.
    print('перо, русский…')
    pl, pr = build_pen([(0.45, 2.58, False), (2.68, 2.94, True),
                        (3.28, 5.46, True)], seed=11)
    write_wav(os.path.join(OUT, 'pen-ru.wav'), pl, pr, 0.62)

    print('перо, английский…')
    pl, pr = build_pen([(0.40, 2.20, False), (2.30, 2.56, True),
                        (2.82, 5.58, True)], seed=12)
    write_wav(os.path.join(OUT, 'pen-en.wav'), pl, pr, 0.62)
    print('готово')
