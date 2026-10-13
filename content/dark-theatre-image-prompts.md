# Dark Theatre – Bild-Prompts für die neun Akte

Für Midjourney (V7) geschrieben, funktionieren aber auch in ChatGPT/DALL·E, Firefly oder Leonardo.
Jeder Prompt = **Szene des Akts** + **Stil-Block** (immer gleich) + **Parameter**.

## So bleibt die Serie einheitlich

1. **Hero als Stil-Referenz:** In Midjourney `--sref` mit dem Hero-Bild. Nach dem Merge liegt es öffentlich unter
   `https://scotandmillfield.studio/darktheatre/assets/img/hero.jpg`. In ChatGPT das Hero-Bild hochladen und dazuschreiben:
   „Use the attached image as the style reference (light, colours, texture).“
2. **Figuren festlegen:** Zuerst Maya und Dante einzeln erzeugen (Prompts ganz unten). Die besten Ergebnisse als
   Figuren-Referenz verwenden (Midjourney V7: `--oref`, V6: `--cref`; ChatGPT: Bild anhängen), damit sie in allen Akten gleich aussehen.
3. **Querformat, groß:** `--ar 3:2`, danach hochskalieren auf mindestens 2400 px Breite.
4. **Kein Text im Bild** – KI-Schrift wird fast immer fehlerhaft. Die Inschrift am Höllentor steht ohnehin im Text.
5. Die finalen Bilder schickt ihr mir; ich ziehe sie farblich auf eine Linie (Schatten, Messing, Korn) und baue sie ein.

## Stil-Block (an jeden Prompt anhängen)

```
dark baroque fantasy, decaying grandeur of an abandoned opera house, single shaft of volumetric light cutting through dust and drifting mist, deep velvet crimson, tarnished brass and old gold, near-black shadows, small brass clockwork gears as a recurring motif, cinematic chiaroscuro, desaturated palette except crimson and gold, fine film grain, highly detailed, 35mm lens, shallow depth of field, no text, no letters
```

Parameter: `--ar 3:2 --style raw --sref [Hero-URL] --v 7`

---

## I · Ouverture
```
The empty stage of a vast ruined theatre, a heavy crimson velvet curtain just beginning to part, a single beam of light falling onto bare dusty stage boards, dust motes hanging in the air, far behind the stage a faint glimpse of a black river and the silhouette of a great stone gate dissolving in mist, no people, stillness before the first note, [STIL-BLOCK]
```

## II · Maya
```
A lone warrior woman from Spain standing at the rocky edge of a dark volcanic crater lake in Italy at dusk, seen three-quarter from behind, dark hair braided back, worn blackened steel and dark leather armour with tarnished brass fittings, a deep crimson sash, a sword at her side, mist rising from the black water, at her feet the earth splits open and ancient stone stairs descend into darkness, a single shaft of light on her shoulder, determined and alone, [STIL-BLOCK]
```

## III · Stygia
```
The river Styx, black and perfectly still, no reflections, hooded ferrymen with dim lanterns standing in long narrow boats, countless pale translucent spirits of the dead rising out of the mist and surging toward the shore, the warrior woman in blackened armour and crimson sash mid-strike, her blade cutting through the shadows, motion and tension, cold light from above, [STIL-BLOCK]
```

## IV · Dante
```
A monumental gate of hell carved from weathered dark stone, an enormous baroque arch overgrown with dust and cobwebs, before it stands a gaunt man in his fifties in a long faded crimson hooded robe with a stern face, the warrior woman in blackened armour walking past him toward the dark opening, tension between them, a single beam of light falling on the threshold, ash drifting in the air, [STIL-BLOCK]
```

## V · Catacomb Waltz
```
Endless candlelit catacomb vaults with niches of skulls, the dead in decayed ballroom finery waltzing in slow circles, faces pale and serene, in the centre of the circle the warrior woman in armour dances with a beautiful pale young man with dark curls and completely empty eyes, soft motion blur on the turning couples around them, warm candle glow against deep shadow, hypnotic and melancholic, [STIL-BLOCK]
```

## VI · Masquerade
```
A subterranean masked ball in a ruined baroque hall, crystal chandeliers flaring with candlelight, ghostly guests in white and gold Venetian masks with empty eye holes forming a tight ring, in the centre the warrior woman in blackened armour tearing a golden mask from the face of a man in a faded crimson robe, the unmasked face of the gaunt older man revealed, frozen moment, [STIL-BLOCK]
```

## VII · Fracture
```
Aftermath on a cracked marble floor in near darkness, the warrior woman lying still, her sword fallen beside her, a shattered golden Venetian mask in the foreground, white masks retreating into the shadows, the man in the faded crimson robe kneeling beside her, a fracture line running through the floor, one thin shaft of cold light, silence and grief, [STIL-BLOCK]
```

## VIII · From the Ashes
```
A heap of grey ash on a dark stone floor beginning to glow from within, embers rising in a slow spiral, a flame taking the shape of a woman’s silhouette, the gaunt man in the faded crimson robe kneeling before it with open hands, his own figure pale and fading as the flame grows, sparks and drifting ash, rebirth in darkness, [STIL-BLOCK]
```

## IX · Curtain Fall
```
A long ancient stone staircase rising toward a distant pale light, the warrior woman climbing upward away from the camera, small in the frame, at the foot of the stairs before a great dark gate stands the silhouette of the man in the crimson robe who stays behind, far below in the darkness faint couples still turning in a waltz, the edge of a heavy crimson theatre curtain sweeping down across the top of the frame, bittersweet and final, [STIL-BLOCK]
```

---

## Figuren-Referenzen (zuerst erzeugen)

**Maya**
```
Portrait of a warrior woman from Spain in her early thirties, olive skin, dark hair braided back, a thin scar across one cheekbone, fierce and grieving eyes, worn blackened steel and dark leather armour with tarnished brass fittings, deep crimson sash, dramatic side light in darkness, [STIL-BLOCK] --ar 2:3
```

**Dante**
```
Portrait of a gaunt man in his fifties with a sharp aquiline profile, hollow cheeks, grave and knowing eyes, a long faded crimson hooded robe of heavy wool, a faint laurel motif embroidered at the collar, dramatic side light in darkness, [STIL-BLOCK] --ar 2:3
```

**Adonis** (nur für Akt V)
```
Portrait of a beautiful pale young man with dark curls, faded ceremonial clothing of a fallen soldier, completely empty distant eyes, serene and lost, candlelight in darkness, [STIL-BLOCK] --ar 2:3
```
