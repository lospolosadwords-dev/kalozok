# Contributing

Thanks for taking a look. This is a hobby project that grew large, so the bar is simple:
**it has to still run.**

## Getting started

Nothing to install, nothing to build:

```bash
git clone https://github.com/lospolosadwords-dev/kalozok.git
cd kalozok
python3 -m http.server 8188     # or: php -S 127.0.0.1:8188 for multiplayer too
```

Open <http://127.0.0.1:8188/?play=1> to skip straight into the game, or add `&lang=en`.

## Before you open a pull request

**1. Check the syntax.** The game is one big inline script, so a stray brace is invisible until the
page is blank:

```bash
S=$(grep -n '^<script>$' index.html | tail -1 | cut -d: -f1); E=$(grep -n '</script>' index.html | tail -1 | cut -d: -f1)
sed -n "$((S+1)),$((E-1))p" index.html > /tmp/_kaloz.js && node --check /tmp/_kaloz.js && echo SYNTAX_OK
```

**2. Run the headless smoke test** — it renders the real game with SwiftShader and fails on any JS
error or failed request:

```bash
npm i playwright
python3 -m http.server 8188 &
node tools/shot.js /tmp/shot.png 9000 "?play=1"
```

A healthy run prints `ships` and `islands` above zero, `JS-hibák: nincs` and
`bukott kérések: nincs` — then look at the PNG. Under SwiftShader the frame rate means nothing;
the image does.

**3. If you touched world generation**, load the same seed three times and confirm the island
layout is identical. See the determinism rule below — it is the easiest thing to break here.

## Things worth knowing

Read [ARCHITECTURE.md](ARCHITECTURE.md) first — especially *Five things that will bite you*. The
short version:

- **The seeded `rand()` is for world generation only.** Runtime effects, timers and spawns use
  `Math.random()`. One extra seeded call shifts the stream and moves every island.
- **No mid-line `//` comments.** They swallow the rest of a very long line.
- **New player-visible text needs an `EN` dictionary entry** (`EN_P` for fragments).
- **Assets must be CC0.** CC BY is not enough for us — see [ASSETS.md](ASSETS.md).
- **PHP 5.6 compatibility** for `mp.php`: `array()` not `[]`, no `??`, no arrow functions, no type
  hints. The live host is old.
- Popups never open by themselves. If you add news or a changelog screen, it waits to be clicked.

## Where the code lives

This repository is a cleaned public mirror: we develop the game inside a private monorepo and
`tools/sync_from_skynet.py` copies it out, re-applying the same removals every time (see
[ASSETS.md](ASSETS.md) for what is stripped and why). It means **pull requests are applied by hand
upstream** and come back on the next sync, so your commit may arrive under a squashed message —
you will be credited in the release notes. Issues and discussion happen here.

## Contact

**John Crystal — [janos@lospolo.hu](mailto:janos@lospolo.hu)** ·
[Discord](https://discord.gg/sBfBdB8AzK)

Ha magyarul kényelmesebb, nyugodtan írj magyarul — az issue-kat és a leveleket is megválaszoljuk.
