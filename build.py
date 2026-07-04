#!/usr/bin/env python3
"""Builds coincircuit-woocommerce.zip, the installable WordPress plugin."""

import os
import zipfile

ROOT = os.path.dirname(os.path.abspath(__file__))
OUTPUT = os.path.join(ROOT, "coincircuit-woocommerce.zip")
FOLDER = "coincircuit-woocommerce"
EXCLUDE = {".git", ".gitignore", "build.py", os.path.basename(OUTPUT)}


def collect_files():
    entries = []
    for base, dirs, names in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in EXCLUDE]
        for name in names:
            if name in EXCLUDE:
                continue
            full = os.path.join(base, name)
            rel = os.path.relpath(full, ROOT).replace(os.sep, "/")
            entries.append((full, FOLDER + "/" + rel))
    entries.sort(key=lambda pair: pair[1])
    return entries


def main():
    entries = collect_files()
    if os.path.exists(OUTPUT):
        os.remove(OUTPUT)

    with zipfile.ZipFile(OUTPUT, "w", zipfile.ZIP_DEFLATED) as archive:
        for full, arcname in entries:
            archive.write(full, arcname)

    print("Wrote %s (%d files)" % (os.path.basename(OUTPUT), len(entries)))


if __name__ == "__main__":
    main()
