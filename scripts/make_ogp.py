#!/usr/bin/env python3
"""OGP（1200×630）とファビコン。Kurage シリーズの型（ライト・青緑・ロゴとマスコット入り・数字と価格は入れない）。  /usr/bin/python3 scripts/make_ogp.py"""
import io
import os
import urllib.request

from PIL import Image, ImageDraw, ImageFont

R = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
B = '/usr/share/fonts/opentype/noto/NotoSansCJK-Bold.ttc'; N = '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc'
TEAL = (10, 143, 133); INK = (20, 34, 47); SUB = (60, 75, 90); LINE = (221, 230, 236)
im = Image.new('RGB', (1200, 630), (244, 247, 249)); d = ImageDraw.Draw(im)
d.rectangle((0, 0, 1200, 12), fill=TEAL)
logo = Image.open(io.BytesIO(urllib.request.urlopen('https://exbridge.jp/images/logo-mark-128.png').read())).convert('RGBA').resize((48, 48))
im.paste(logo, (60, 56), logo)
d.text((120, 62), 'Kurage 陳情・名簿', font=ImageFont.truetype(B, 32), fill=(7, 117, 109))
d.text((60, 150), '議員事務所の', font=ImageFont.truetype(B, 50), fill=INK)
d.text((60, 222), '名簿・訪問・', font=ImageFont.truetype(B, 84), fill=INK)
d.text((60, 326), '陳情管理', font=ImageFont.truetype(B, 84), fill=(7, 117, 109))
d.text((60, 450), '後援会の名簿を、事務所の中のサーバーで', font=ImageFont.truetype(N, 27), fill=SUB)
# 右：陳情の段階の見本
x0, y0 = 690, 96
d.rounded_rectangle((x0, y0, x0 + 450, y0 + 440), radius=16, fill=(255, 255, 255), outline=LINE, width=2)
cols = [('受付', [('学童保育の待機', '期限 11-22', 0)]), ('行政へ照会', [('通学路の白線', '期限 11-15', 0), ('バス路線の減便', '期限 11-11', 1)]), ('回答待ち', [('側溝のふた', '過ぎています', 2)])]
cw = 134
for k, (name, cards) in enumerate(cols):
    cx = x0 + 14 + k * (cw + 8)
    d.rounded_rectangle((cx, y0 + 16, cx + cw, y0 + 250), radius=10, fill=(234, 241, 243))
    d.text((cx + 10, y0 + 24), name, font=ImageFont.truetype(B, 16), fill=INK)
    for j, (t, due, lv) in enumerate(cards):
        cy = y0 + 56 + j * 92
        d.rounded_rectangle((cx + 6, cy, cx + cw - 6, cy + 82), radius=8, fill=(255, 255, 255), outline=LINE)
        d.text((cx + 14, cy + 10), t, font=ImageFont.truetype(B, 14), fill=INK)
        bg, fg = [((238, 242, 245), (74, 90, 102)), ((255, 242, 214), (122, 82, 0)), ((253, 231, 228), (192, 57, 43))][lv]
        w = d.textlength(due, font=ImageFont.truetype(B, 12)) + 16
        d.rounded_rectangle((cx + 14, cy + 46, cx + 14 + w, cy + 70), radius=12, fill=bg); d.text((cx + 22, cy + 50), due, font=ImageFont.truetype(B, 12), fill=fg)
# 訪問の順番の見本
d.text((x0 + 20, y0 + 268), '訪問計画　事務所から近い順', font=ImageFont.truetype(B, 17), fill=INK)
pts = [(x0 + 50, y0 + 400), (x0 + 120, y0 + 340), (x0 + 200, y0 + 372), (x0 + 270, y0 + 322), (x0 + 350, y0 + 352), (x0 + 405, y0 + 400)]
for a, b in zip(pts, pts[1:]):
    n = 9
    for i in range(0, n, 2):
        d.line((a[0] + (b[0] - a[0]) * i / n, a[1] + (b[1] - a[1]) * i / n, a[0] + (b[0] - a[0]) * (i + 1) / n, a[1] + (b[1] - a[1]) * (i + 1) / n), fill=TEAL, width=3)
for i, (px, py) in enumerate(pts):
    c = (217, 119, 6) if i == 0 else TEAL
    d.ellipse((px - 16, py - 16, px + 16, py + 16), fill=c, outline=(255, 255, 255), width=3)
    t = '事' if i == 0 else str(i); f = ImageFont.truetype(B, 16); d.text((px - d.textlength(t, font=f) / 2, py - 12), t, font=f, fill=(255, 255, 255))
m = Image.open('/home/kojima/work/kurage_web/images/kurage-mascot-cutout.png').convert('RGBA'); m = m.resize((110, int(110 * m.height / m.width)))
im.paste(m, (60, 630 - m.height - 14), m)
d.text((190, 572), '株式会社エクスブリッジ', font=ImageFont.truetype(N, 22), fill=(93, 107, 122))
im.save(os.path.join(R, 'public', 'kcm_ogp.png'), optimize=True)
mm = Image.open('/home/kojima/work/kurage_web/images/kurage-mascot-cutout.png').convert('RGBA')
s = 64 / max(mm.width, mm.height); mm = mm.resize((max(1, int(mm.width * s)), max(1, int(mm.height * s))), Image.LANCZOS)
fv = Image.new('RGBA', (64, 64), (0, 0, 0, 0)); fv.paste(mm, ((64 - mm.width) // 2, (64 - mm.height) // 2), mm)
fv.save(os.path.join(R, 'public', 'kcm_favicon.png')); print('ok')
