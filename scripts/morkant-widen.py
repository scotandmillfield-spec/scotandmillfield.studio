"""Morkant: erweitert das Hochformat-Fährmann-Bild links und rechts auf 16:9.
Oben wird die Skyline gespiegelt fortgesetzt, unten entstehen Wasser und Nebel aus den Randfarben;
nach außen zunehmend unscharf und dunkel. Kein KI-Inhalt – nur das Bild selbst.
Aufruf: python3 scripts/morkant-widen.py <original> <ausgabe.jpg>
"""
import sys
import numpy as np
from PIL import Image, ImageFilter

src, dst = sys.argv[1], sys.argv[2]
im = Image.open(src).convert("RGB")
W, H = im.size
TW = round(H * 16 / 9); side = (TW - W + 1) // 2
a = np.asarray(im).astype(np.float32)

def fog_noise(h, w, seed):
    r = np.random.default_rng(seed); acc = np.zeros((h, w), np.float32); amp = 1.0
    for sc in (60, 120, 240):
        n = r.random((h // sc + 2, w // sc + 2)).astype(np.float32)
        n = np.asarray(Image.fromarray((n * 255).astype(np.uint8)).resize((w, h), Image.Resampling.BICUBIC)).astype(np.float32) / 255
        acc += n * amp; amp *= 0.6
    acc = np.asarray(Image.fromarray((acc / acc.max() * 255).astype(np.uint8)).filter(ImageFilter.GaussianBlur(14))).astype(np.float32) / 255
    return acc

def side_fill(which):
    strip = a[:, :150] if which == "left" else a[:, -150:]
    s = strip[:, ::-1]; tiles = []
    while sum(t.shape[1] for t in tiles) < side: tiles.append(s); s = s[:, ::-1]
    sky = np.concatenate(tiles, 1)[:, :side]
    if which == "left": sky = sky[:, ::-1]
    edge = (a[:, :12] if which == "left" else a[:, -12:]).mean(1, keepdims=True)
    smear = np.repeat(edge, side, 1)
    y = np.arange(H)[:, None, None]
    wv = np.clip((y - 700) / 220, 0, 1)
    fill = sky * (1 - wv) + smear * wv
    img = Image.fromarray(fill.clip(0, 255).astype(np.uint8))
    return (fill, np.asarray(img.filter(ImageFilter.GaussianBlur(18))).astype(np.float32),
            np.asarray(img.filter(ImageFilter.GaussianBlur(55))).astype(np.float32))

out = np.zeros((H, TW, 3), np.float32)
for which in ("left", "right"):
    f, b1, b2 = side_fill(which)
    x = np.arange(side, dtype=np.float32)
    d = ((side - x) / side if which == "left" else (x + 1) / side)[None, :, None]
    mix = f * (1 - np.clip(d * 4, 0, 1)) + b1 * np.clip(d * 4, 0, 1)
    mix = mix * (1 - np.clip((d - .3) / .7, 0, 1)) + b2 * np.clip((d - .3) / .7, 0, 1)
    fog = fog_noise(H, side, 1 if which == "left" else 2)[..., None]
    yy = np.arange(H)[:, None, None] / H
    fogmask = np.clip((yy - .35) / .4, 0, 1) * np.clip((1 - yy) / .25, 0, 1)
    mix = mix + fog * fogmask * 34 * (1 - d * .6)
    mix = mix * (1 - .7 * d ** 1.3)
    if which == "left": out[:, :side] = mix
    else: out[:, side + W:] = mix[:, :TW - side - W]
out[:, side:side + W] = a
F = 60
for i in range(F):
    t = (i + 1) / (F + 1)
    for col, ext in ((side + i, side - 1 - i), (side + W - 1 - i, side + W + i)):
        out[:, col] = out[:, col] * t + out[:, ext] * (1 - t)
out += np.random.default_rng(3).normal(0, 2.2, out.shape)
Image.fromarray(out.clip(0, 255).astype(np.uint8)).save(dst, quality=86, optimize=True, progressive=True)
print(f"{W}x{H} -> {TW}x{H}")
