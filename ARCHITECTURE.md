# ARCHITECTURE.md — the map

The game is one file. `index.html` is ~4 300 lines: a `<style>` block, the HUD markup, and then a
single large inline `<script>` holding the whole game. That is unusual, and deliberate — it deploys
by copying one file, and there is never a build that can go stale. The cost is that you need a map,
which is what this document is.

## Boot order

1. Three.js r128 from the CDN, then the example modules in `lib/` (`Water`, `Sky`, `GLTFLoader`,
   `SkeletonUtils`, and the seven post-processing files).
2. Language layer — `LANG` from `localStorage` / `?lang=`, dictionaries `EN` and `EN_P`.
3. Renderer, post chain, sky and water.
4. **World generation** from the persisted seed.
5. Menu → intro → `startGame()` (or `continueGame()` from a save).
6. `loop(now)` → `update(dt)` (physics, AI, combat, economy) → `placeShip` → `hud()` / `drawFX`.

## Key functions by system

| System | Where to look |
|---|---|
| World generation | `_mulberry32`, `WORLD_SEED`, `rand`, `makeIsland`, `sculptCoast`, `makeMountain`, `WHIRL` |
| Sky, day/night, weather | `updateEnvironment`, `PAL`, `dayT`, `sunEl`, `bakeEnv`, `storm`, lightning + `sfxThunder` |
| Post-processing | `EffectComposer` chain: `RenderPass` → `UnrealBloomPass` → `FXAAShader` → custom `GradeShader` (contrast, saturation, warm tint, vignette, sRGB at the very end) |
| Ships and models | `fitModel`, `applyPlayerModel`, `applyShipModel`, `applyHullTriplanar`, `makeChar`, `applyCaptainToPlayer` |
| Sailing and physics | `sailShip`, `placeShip`, `resolveCollisions`, `avoidObstacles`, `updateWakes` |
| Combat | `fireShip(s, power, side)`, `muzzleBlast`, `camShake`, `tearSail`, `shipHit`, `disposeShip` |
| Captain abilities | `ABILITIES`, `useAbility`, `_abBake` / `abTex` / `_abIcon` (the brass medallions are baked to an offscreen canvas) |
| Deck view | `openDeckView`, `drawDeckView`, `updateDeckAgents`, `crewJobs`, `deckDmg`, `DECK_UPG`, `doDeckUpgrade`, `cannonReloadMul()`, `sailCrewMul()` |
| Economy and ports | `genOffer`, port specialities, price drift in `update`, upgrade tree, `openTavern`, `genCandidates`, `officer*Mul` |
| Sectors | `SECTORS`, `sector`, `openSectorMap` — sectors only scale multipliers, they never regenerate the world |
| Tutorial | `TUT`, `TUT_STEPS`, `tutStart`, `tutTick`, `tutRender` |
| Audio | `updateAudioLayers` and the `sfx*` functions — everything is Web Audio, no files |
| Save | `saveGame`, `loadGame`, `continueGame`, `clearSave` (`localStorage` key `kaloz_save`) |
| Multiplayer client | the `MP` object and the `mp*` functions — see [MULTIPLAYER.md](MULTIPLAYER.md) |
| Quality / resolution | `applyQuality`, `autoRes`, `_TOUCHDEV`, `SETTINGS.rscale`, `resizePost` |

## Debug URL parameters

`?play=1` skips the intro straight into the game. Then:
`goport=1` (start docked) · `treasure=1` · `storm=1` · `facesun=1` · `deck=1` · `dlgtest=1` ·
`whirl=1` · `bigwaves=1` · `fig=N` · `kraken=1` · `serpent=1` · `ghost=1` · `armada=1` ·
`lang=en`.

They compose: `?play=1&lang=en&storm=1`.

## Five things that will bite you

These are not style preferences — each one cost us a debugging session.

1. **The seeded `rand()` belongs to world generation and nothing else.** Never call it for
   textures, effects, timers or spawns, and never from a branch that only *sometimes* runs: a
   different number of calls per load shifts the whole random stream and the islands move. Use
   `Math.random()` for anything at runtime, or give your feature its own fixed-seed generator
   (`_boltR` / `_texR` are the pattern). Verify by loading the same seed three times and comparing
   the island layout.

2. **A `//` comment mid-line swallows the rest of the line.** Many lines here hold a dozen
   statements, and the closing `}` of a function may live at the end of one. Use `/* … */` inline,
   or put the comment on its own line. This has produced "Unexpected end of input" twice.

3. **Never detect mobile with `maxTouchPoints` or `ontouchstart` alone** — every touchscreen
   Windows laptop is a false positive. And any automatic quality reduction must be *two-way* and
   user-overridable, otherwise it silently ruins the image until the page is reloaded.

4. **`EffectComposer` (r128) freezes the pixel ratio in its constructor.** If quality settings
   raise the renderer's ratio afterwards, the post chain stays at the old resolution. `resizePost()`
   must always call `composer.setPixelRatio()`.

5. **Every new player-visible string needs an `EN` entry** (or `EN_P` for concatenated fragments),
   or it stays Hungarian in English mode.

Two smaller ones: the captain model faces forward with `rotation.y = 0` for both player and AI —
keep them consistent; and `disposeShip()` must stay, or sunk ships leak animation mixers.

## Hungarian is the source language

UI text is written in Hungarian and translated at render time (`TX`, `applyLangDOM`). Contributions
in English are welcome — write the Hungarian string as the key and add the English value; if your
Hungarian is shaky, say so in the pull request and we will fill it in.
