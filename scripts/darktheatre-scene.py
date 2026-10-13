"""Dark Theatre: Bild für einen Akt farblich angleichen und den Akt als Bühnenbild einbauen.

Aufruf:  python3 scripts/darktheatre-scene.py <akt 1-9> <bilddatei> [--right] [--pos "60% 50%"]
  --right   Text rechts statt links (wenn die Hauptfigur links im Bild steht)
  --pos     Bildausschnitt (CSS object-position), Standard "50% 50%"
Erzeugt darktheatre/assets/img/act-N.jpg, act-N.webp, act-N-900.webp und passt darktheatre/index.html an.
"""
import re, sys
import numpy as np
from PIL import Image

args = sys.argv[1:]
act, src = int(args[0]), args[1]
right = "--right" in args
pos = args[args.index("--pos") + 1] if "--pos" in args else "50% 50%"
ROMAN = ["I", "II", "III", "IV", "V", "VI", "VII", "VIII", "IX"]

# --- Farbangleichung: dunkler, etwas weniger Sättigung, Schatten Richtung Bordeaux-Schwarz, Lichter Richtung Messing
im = Image.open(src).convert("RGB")
a = np.asarray(im).astype(np.float32) / 255
lum = lambda x: (0.2126 * x[..., 0] + 0.7152 * x[..., 1] + 0.0722 * x[..., 2])[..., None]
l = lum(a); a = l + (a - l) * 0.88
a = np.power(np.clip(a, 0, 1), 1.18) * 0.86
bg, brass = np.array([10, 7, 8]) / 255, np.array([217, 187, 146]) / 255
l = lum(a)
w_sh = np.clip(1 - l * 3, 0, 1) * 0.35
w_hi = np.clip((l - 0.55) / 0.45, 0, 1) * 0.18
a = a * (1 - w_sh) + bg * w_sh
a = a * (1 - w_hi) + brass * w_hi
out = Image.fromarray((np.clip(a, 0, 1) * 255 + 0.5).astype(np.uint8))
if out.width > 2400:
    out = out.resize((2400, round(out.height * 2400 / out.width)), Image.Resampling.LANCZOS)
d = "darktheatre/assets/img/"
out.save(f"{d}act-{act}.jpg", quality=84, optimize=True, progressive=True)
out.save(f"{d}act-{act}.webp", quality=80, method=6)
out.resize((900, round(out.height * 900 / out.width)), Image.Resampling.LANCZOS).save(f"{d}act-{act}-900.webp", quality=78, method=6)
W, H = out.size

# --- HTML: Akt als Bühnenbild
p = "darktheatre/index.html"
s = open(p).read()
m = re.search(rf'    <section class="act[^"]*" id="act-{act}".*?</section>', s, re.S)
sec = m.group(0)
cls = "act act--scene" + (" act--right" if right else "")
pic = f'''      <div class="act__scene" aria-hidden="true">
        <picture>
          <source type="image/webp" srcset="assets/img/act-{act}-900.webp 900w, assets/img/act-{act}.webp {W}w" sizes="100vw">
          <img src="assets/img/act-{act}.jpg" alt="" width="{W}" height="{H}" loading="lazy" style="object-position: {pos}">
        </picture>
      </div>
'''
if 'class="act__scene"' in sec:
    sec = re.sub(r'      <div class="act__scene".*?</div>\n', pic, sec, count=1, flags=re.S)
    sec = re.sub(r'<section class="act[^"]*"', f'<section class="{cls}"', sec, count=1)
else:
    lines = sec.split("\n")
    head, body = lines[0], lines[1:-1]
    body = [l for l in body if 'class="act__numeral"' not in l]
    body = [("  " + l if l.strip() else l) for l in body]
    sec = "\n".join([re.sub(r'<section class="act[^"]*"', f'<section class="{cls}"', head), pic.rstrip("\n"),
                     '      <div class="act__body">', *body, "      </div>", "    </section>"])
s = s[:m.start()] + sec + s[m.end():]
s = re.sub(r"\?v=[\w-]+", lambda _: "?v=" + __import__("datetime").date.today().strftime("%Y%m%d") + f"s{act}", s)
open(p, "w").write(s)
print(f"Akt {ROMAN[act-1]}: {W}x{H}, Text {'rechts' if right else 'links'}, Ausschnitt {pos}")
