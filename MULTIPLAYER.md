# MULTIPLAYER.md — how 2–8 pirates share one sea

There is no WebSocket, no game server and no database. `mp.php` is a **short-polling relay** on
ordinary shared hosting: rooms are JSON files under `mp_rooms/`, guarded with `flock`. The client
polls roughly every 120 ms. It is deliberately unambitious, and it works.

## Running it

Any PHP that can write next to the script will do:

```bash
php -S 127.0.0.1:8188
```

Open the game, **Multiplayer** in the menu, create a room, and share the four-character code.
`mp_rooms/` is created at runtime and is git-ignored — never commit it.

## Endpoints — `mp.php?a=…`

| Call | Body | Returns |
|---|---|---|
| `create` | `{name, seed?}` | `{code, pid, token, seed, settings}` |
| `join` | `{code, name}` | `{code, pid, token, seed, settings, phase}` |
| `poll` | `{code, pid, token, st, chat?, ready?, color?, settings?, start?, enemies?, events?, kick?, csince?, esince?}` | full room snapshot; chat and events come back as deltas |
| `leave` | `{code, pid, token}` | `{ok}` |

`pid` identifies a player, `token` proves it is you — every mutating call is token-gated, so knowing
someone's `pid` is not enough to kick them or speak for them. Players time out after **9 s** of
silence, rooms are deleted after **6 h**, and a room holds at most **8** players.

## The trust model

- **Each client is authoritative over its own ship.** Position, heading and sail state are simply
  reported. This is a game you play with people you know; there is no anti-cheat.
- **The host simulates the AI ships** (`enemies[]`) and broadcasts them. Clients render what they
  are told.
- **PvP hits are events**, not state: `events[]` entries carry a `to` field (`pid`, `all` or
  `host`). A client that hits a host-owned bot sends a `hostHit` event rather than deciding damage.
- **Host migration:** if the host disappears, the longest-connected remaining player takes over.
- **The world is shared through the seed.** The host's seed goes into `sessionStorage`, the page
  reloads, and the boot-time seed init picks it up — so everyone gets the same islands without
  transferring any world data. The single-player seed in `localStorage` is left alone.

## On the client

Everything is gated behind `if (!MP.active)`, `if (!_MPBOOT)` or `if (MP.isHost)`, so single-player
runs exactly as it did before multiplayer existed. Remote ships are interpolated in `mpSyncRemotes`
/ `mpFrame` with name plates; bots arrive via `mpSyncEnemies`; chat is `T`; joins, leaves and kills
show up in `#mpFeed`. The co-op Kraken fight synchronises through `mpKrakenSt` / `mpApplyKraken`
and the `khit` / `kslam` / `kdead` events.

## Known limits

Honest list, in case you want to fix one:

- Bots actively hunt the host; remote players only get hit by stray broadsides.
- Remote ships are not in `resolveCollisions`, so they can overlap each other and drift through
  islands.
- Remote cannonballs are not drawn — only the impact effect at the victim.
- A bot killed by a client pays no gold (co-op nicety, not a design decision).
- The co-op Kraken has been tested synthetically (simulated host and guest in one page), not yet on
  two real machines.
