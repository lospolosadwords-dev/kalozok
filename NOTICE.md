# Third-party notices

The game code is MIT licensed — see [LICENSE](LICENSE). Bundled third-party material keeps its own
terms:

| What | Where | Licence |
|---|---|---|
| Ship, character and nature models | `assets/*.glb`, `assets/dutchship/` | **CC0** — [Poly Haven](https://polyhaven.com/), [Quaternius](https://quaternius.com/). Full list in [ASSETS.md](ASSETS.md). |
| Terrain and material textures | `assets/*.jpg` | **CC0** — Poly Haven, except `waternormals.jpg` (Three.js examples, MIT). |
| Pirate portraits | `assets/portraits/ai*.png` | **CC0** — AI-generated for this game. |
| Three.js example modules | `lib/*.js` | **MIT** — © 2010–2026 three.js authors |
| Three.js r128 core | loaded from `cdnjs.cloudflare.com`, not vendored | **MIT** |

Everything not listed above — all game code, the procedural sky, water tuning, HUD, deck view,
economy, AI and multiplayer relay — is original work by Los Polos Amigos Kft.

No sound or music files are bundled: the entire soundtrack and every sound effect is synthesised at
runtime with the Web Audio API.
