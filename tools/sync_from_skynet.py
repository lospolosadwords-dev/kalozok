#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Los Polos Kalózok — publikus kiadás szinkronizálása a privát fejlesztői repóból.

    python3 tools/sync_from_skynet.py [--src ~/Desktop/Skynet/kalozok] [--dry-run]

Az IGAZSÁGFORRÁS a privát repó `kalozok/` mappája (onnan megy az FTP-deploy is).
Ez a szkript átmásolja a publikálható fájlokat, és MINDEN körben újra alkalmazza
ugyanazokat a tisztító foltokat, hogy a két repó ne tudjon elcsúszni egymástól.

A tisztítási SZABÁLYOK nincsenek beledrótozva: a `tools/release_rules.json`-ból
jönnek, ami szándékosan NEM része a publikus repónak (személyes adatokat nevez
meg — épp azt, amit el kell távolítani). Minta: `release_rules.example.json`.

Minden folt „horgonyos": ha a horgony nincs meg (mert a forrás átalakult),
a szkript MEGÁLL hibával — inkább szóljon, mint hogy csendben kihagyja.
"""

import argparse
import filecmp
import json
import os
import re
import shutil
import sys

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEFAULT_SRC = os.path.expanduser("~/Desktop/Skynet/kalozok")
DEFAULT_RULES = os.path.join(REPO, "tools", "release_rules.json")

# ── Mi kerül át ─────────────────────────────────────────────────────────────
COPY_FILES = ["mp.php"]  # az index.html a foltozó ágon megy át (lásd lent)
COPY_DIRS = ["lib", "assets"]

# Fájlnév-minták, amik sosem kellenek (a szabály-fájl továbbiakat adhat hozzá).
EXCLUDE_PATTERNS = [
    re.compile(r"^_"),            # _dbg*.js, _kraken*.png — fejlesztői firkák
    re.compile(r"\.bak\.html$"),
    re.compile(r"^\.DS_Store$"),
]


def load_rules(path):
    """
    Szabály-fájl szerkezete:
      {
        "exclude_names": ["valami.png", ...],       # ezek a fájlok nem másolódnak
        "patches": [                                 # foltok az index.html-en
          {"desc": "...", "find": "<regex>", "repl": "...", "expect": 1}
        ],
        "forbidden": ["...", ...]                    # ezek egyike sem maradhat bent
      }
    """
    if not os.path.isfile(path):
        sys.exit(
            "HIBA: nincs szabály-fájl: %s\n"
            "      Ez a fájl szándékosan nincs a publikus repóban (személyes adatokat nevez meg).\n"
            "      Másold le a mintát és töltsd ki:\n"
            "        cp tools/release_rules.example.json tools/release_rules.json" % path
        )
    with open(path, encoding="utf-8") as fh:
        rules = json.load(fh)
    patches = []
    for p in rules.get("patches", []):
        patches.append((p["desc"], re.compile(p["find"], re.S if p.get("dotall") else 0),
                        p["repl"], int(p.get("expect", 1))))
    return set(rules.get("exclude_names", [])), patches, rules.get("forbidden", [])


def excluded(name, exclude_names):
    if name in exclude_names:
        return True
    return any(p.search(name) for p in EXCLUDE_PATTERNS)


def copy_tree(src, dst, dry, exclude_names):
    """Rekurzív másolás a kizárásokkal. Visszaadja a megváltozott fájlok listáját."""
    changed = []
    for root, dirs, files in os.walk(src):
        dirs[:] = sorted(d for d in dirs if not excluded(d, exclude_names))
        rel = os.path.relpath(root, src)
        target_dir = dst if rel == "." else os.path.join(dst, rel)
        for f in sorted(files):
            if excluded(f, exclude_names):
                continue
            s, d = os.path.join(root, f), os.path.join(target_dir, f)
            if os.path.exists(d) and filecmp.cmp(s, d, shallow=False):
                continue
            changed.append(os.path.relpath(d, REPO))
            if not dry:
                os.makedirs(target_dir, exist_ok=True)
                shutil.copy2(s, d)
    return changed


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--src", default=DEFAULT_SRC, help="a privát repó kalozok/ mappája")
    ap.add_argument("--rules", default=DEFAULT_RULES, help="tisztítási szabályok (JSON)")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()

    src = os.path.expanduser(args.src)
    if not os.path.isfile(os.path.join(src, "index.html")):
        sys.exit("HIBA: nincs index.html itt: %s" % src)

    exclude_names, patches, forbidden = load_rules(os.path.expanduser(args.rules))

    changed = []
    for f in COPY_FILES:
        s, d = os.path.join(src, f), os.path.join(REPO, f)
        if not os.path.isfile(s):
            sys.exit("HIBA: hiányzik a forrásból: %s" % f)
        if not (os.path.exists(d) and filecmp.cmp(s, d, shallow=False)):
            changed.append(f)
            if not args.dry_run:
                shutil.copy2(s, d)

    for dname in COPY_DIRS:
        s = os.path.join(src, dname)
        if os.path.isdir(s):
            changed += copy_tree(s, os.path.join(REPO, dname), args.dry_run, exclude_names)

    # ── foltozás ────────────────────────────────────────────────────────────
    # MINDIG a FORRÁS index.html-jét foltozzuk (nem a már foltozott másolatot),
    # különben a --dry-run a saját korábbi eredményén bukna el.
    index_path = os.path.join(REPO, "index.html")
    html = open(os.path.join(src, "index.html"), encoding="utf-8").read()
    for desc, rx, repl, expect in patches:
        html, n = rx.subn(repl.replace("\\", "\\\\"), html, count=expect)
        if n != expect:
            sys.exit(
                "HIBA: a folt horgonya nem található (%dx várt, %dx talált): %s\n"
                "      A forrás átalakult — frissítsd a szabály-fájlt." % (expect, n, desc)
            )
        print("  folt OK — %s" % desc)

    for bad in forbidden:
        if bad in html:
            sys.exit("HIBA: tiltott hivatkozás maradt az index.html-ben (%d karakter hosszú minta)"
                     % len(bad))

    old = open(index_path, encoding="utf-8").read() if os.path.exists(index_path) else None
    if html != old:
        if "index.html" not in changed:
            changed.append("index.html")
        if not args.dry_run:
            open(index_path, "w", encoding="utf-8").write(html)

    ver = re.search(r"<title>[^<]*?(v[\d.]+)</title>", html)
    print("\nVerzió: %s" % (ver.group(1) if ver else "ismeretlen"))
    print("Változott fájlok: %d" % len(changed))
    for c in changed[:20]:
        print("  %s" % c)
    if len(changed) > 20:
        print("  … és még %d" % (len(changed) - 20))
    if args.dry_run:
        print("\n(dry-run — semmi nem íródott ki)")
    else:
        print("\nKész. Ellenőrizd: python3 -m http.server 8188 → http://127.0.0.1:8188/")


if __name__ == "__main__":
    main()
