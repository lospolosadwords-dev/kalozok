# ASSETS.md — where the art comes from

> **Licence status: 100 % CC0 / public domain**, except the Three.js example modules in `lib/`,
> which are MIT. Attribution is not legally required for CC0 — we list everything anyway.

Everything else you see in the game is drawn **in code**: the HUD, the compass and minimap, the
brass ability medallions, the deck cutaway and its pirates, the sail canvas texture, the fire,
smoke, foam and wake sprites, the sky gradient and the stars. That is why the whole game is 18 MB
with the models included.

## 3D models

| File | Source | Licence |
|---|---|---|
| `assets/dutchship/` (glTF + PBR textures) | **[Poly Haven](https://polyhaven.com/)** — *Dutch Ship Medium* | CC0 |
| `assets/sail_ship.glb`, `ship3.glb`, `small_ship.glb` | **[Quaternius](https://quaternius.com/)** — ship packs | CC0 |
| `assets/pirate_captain.glb` | **[Quaternius](https://quaternius.com/)** — rigged pirate character (idle animation) | CC0 |
| `assets/palmA.glb`, `birdB.glb` | **[Quaternius](https://quaternius.com/)** — nature pack | CC0 |

## Textures

| File | Source | Licence |
|---|---|---|
| `assets/rock_diff.jpg`, `rock_rough.jpg` | **[Poly Haven](https://polyhaven.com/)** photogrammetry rock | CC0 |
| `assets/grass.jpg`, `sand.jpg`, `wood.jpg` | **[Poly Haven](https://polyhaven.com/)** | CC0 |
| `assets/waternormals.jpg` | **Three.js** examples (used by `lib/Water.js`) | MIT |

## Portraits

`assets/portraits/ai1.png` … `ai8.png` are **AI-generated** pirate faces made for this game; they
are ours to give away and fall under the same CC0 terms as the rest of the art. Every captain is
mapped to one of them by a hash of their name, so a given pirate always has the same face.

The build we run internally also had five photographs of colleagues wired to five pirate names.
Those are **not** in this repository, and the four names modelled on them were replaced with
generic ones — see `tools/sync_from_skynet.py`, which re-applies that cleanup on every sync.

## Code libraries

| File | Source | Licence |
|---|---|---|
| `lib/Water.js`, `Sky.js`, `GLTFLoader.js`, `SkeletonUtils.js`, `EffectComposer.js`, `RenderPass.js`, `ShaderPass.js`, `CopyShader.js`, `FXAAShader.js`, `LuminosityHighPassShader.js`, `UnrealBloomPass.js` | Three.js r128 examples | MIT |
| `three.min.js` (loaded from cdnjs, not vendored) | Three.js r128 | MIT |

## If you contribute art

**CC0 or public domain only.** CC BY is not enough for us: attribution obligations travelling with
a game file are exactly the kind of thing that gets lost, and we would rather not hand anyone a
licence problem. This has cost us real work on another project — assets had to be stripped out of
a repository after the fact because a credits screen claimed a licence the files did not have.
Check the source page, not the credits.
