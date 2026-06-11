#!/usr/bin/env python3
"""Generates assets/img/icon-192.png and icon-512.png (pure Python, no deps).

Purple panel icon with a white "123" glyph. 192px is rounded; 512px is a
full-bleed square suitable for `purpose: maskable`.
Run from the plugin root:  python3 bin/build-icons.py
"""
import struct, zlib

BG = (0x67, 0x50, 0xA4)   # --md-primary
FG = (0xFF, 0xFF, 0xFF)

DIGITS = {
    '1': ["..#..", ".##..", "..#..", "..#..", "..#..", "..#..", ".###."],
    '2': [".###.", "#...#", "....#", "...#.", "..#..", ".#...", "#####"],
    '3': [".###.", "#...#", "....#", "..##.", "....#", "#...#", ".###."],
}

def glyph_rows(text):
    rows = []
    for r in range(7):
        row = []
        for i, ch in enumerate(text):
            if i:
                row.append('.')
            row.extend(DIGITS[ch][r])
        rows.append(row)
    return rows  # 7 x 17 for "123"

def render(size, rounded):
    rows = glyph_rows('123')
    gh, gw = len(rows), len(rows[0])
    scale = int(size * 0.62 / gw)
    ox = (size - gw * scale) // 2
    oy = (size - gh * scale) // 2
    radius = size * 0.22 if rounded else 0

    px = bytearray()
    for y in range(size):
        px.append(0)  # PNG filter type 0 per scanline
        for x in range(size):
            a = 255
            if radius:
                # corner rounding via distance from the nearest corner circle
                cx = radius if x < radius else (size - radius if x > size - radius else None)
                cy = radius if y < radius else (size - radius if y > size - radius else None)
                if cx is not None and cy is not None:
                    if (x - cx) ** 2 + (y - cy) ** 2 > radius ** 2:
                        a = 0
            gx, gy = (x - ox) // scale, (y - oy) // scale
            on = 0 <= gx < gw and 0 <= gy < gh and rows[gy][gx] == '#'
            r, g, b = FG if on else BG
            px += bytes((r, g, b, a))
    return bytes(px)

def chunk(tag, data):
    return struct.pack('>I', len(data)) + tag + data + struct.pack('>I', zlib.crc32(tag + data) & 0xFFFFFFFF)

def write_png(path, size, rounded):
    ihdr = struct.pack('>IIBBBBB', size, size, 8, 6, 0, 0, 0)
    raw = render(size, rounded)
    png = b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', ihdr) + chunk(b'IDAT', zlib.compress(raw, 9)) + chunk(b'IEND', b'')
    open(path, 'wb').write(png)
    print(path, len(png), 'bytes')

write_png('assets/img/icon-192.png', 192, rounded=True)
write_png('assets/img/icon-512.png', 512, rounded=False)
