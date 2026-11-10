#!/usr/bin/env python3
"""手元の試験環境（outputs/test/www をデモ設定で php -S 起動したもの）を実際のブラウザで通しで操作する。
  DB を消してから流す（rm outputs/test/www/kcm_data/*.sqlite*）。 /usr/bin/python3 scripts/e2e.py <base_url> [--ai] → outputs/test/shots/*.png。320・360・390px で横はみ出しも測る"""
import os
import sys

from playwright.sync_api import sync_playwright

B = sys.argv[1].rstrip('/') + '/'
AI = '--ai' in sys.argv
OUT = os.path.join(os.path.dirname(os.path.dirname(os.path.abspath(__file__))), 'outputs', 'test', 'shots'); os.makedirs(OUT, exist_ok=True)
CSV = os.path.join(os.path.dirname(OUT), 'import.csv')
open(CSV, 'w', encoding='utf-8').write('氏名,ふりがな,住所,電話番号,地区,支持度\n試験 太郎,しけん たろう,愛知県一宮市本町1-1-1,0586-70-0001,中央,A\n,,,,,\n試験 花子,しけん はなこ,愛知県一宮市本町1-1-1,0586-70-0001,中央,B\n')
errs = []


def shot(pg, n): pg.screenshot(path=os.path.join(OUT, n + '.png'), full_page=True)


def ok(pg, word):
    c = pg.content()
    for bad in ('Fatal error', 'Warning</b>', 'Notice</b>', 'Deprecated</b>', 'Uncaught'):
        assert bad not in c, f'{bad} on {pg.url}'
    assert word in c, f'"{word}" not on {pg.url}'


with sync_playwright() as p:
    b = p.chromium.launch(); pg = b.new_page(viewport={'width': 1360, 'height': 900})
    pg.on('pageerror', lambda e: errs.append(str(e)))
    pg.goto(B); shot(pg, '0-login'); pg.locator('button:has-text("として試す")').first.click(); pg.wait_for_load_state()
    ok(pg, '期限が近い陳情・相談'); shot(pg, '1-home')
    # 名簿：絞り込み・1人加える（重複の確認）・人の画面で訪問を記録
    pg.goto(B + '?p=people&area=大和&sort=visit'); ok(pg, '大和'); shot(pg, '2-people')
    pg.goto(B + '?p=person_new'); pg.fill('input[name=name]', '試験 次郎'); pg.fill('input[name=addr]', '愛知県一宮市本町2-2-2'); pg.fill('input[name=phone]', '0586-70-0009'); pg.click('button:has-text("名簿に加える")'); pg.wait_for_load_state()
    ok(pg, '名簿に加えました'); pid = pg.url.split('id=')[1]; print('new person', pid, 'pos:', '位置を直す' in pg.content())
    pg.goto(B + '?p=person_new'); pg.fill('input[name=name]', '試験　次郎'); pg.fill('input[name=phone]', '0586700009'); pg.click('button:has-text("名簿に加える")'); pg.wait_for_load_state()
    ok(pg, '同じ人がすでに名簿にあるようです'); shot(pg, '3-dup')
    pg.goto(B + '?p=person&id=' + pid); pg.check('input[name=result][value=met]'); pg.fill('textarea[name=memo]', '会報を渡した'); pg.click('button:has-text("訪問を記録する")'); pg.wait_for_load_state()
    ok(pg, '会報を渡した'); pg.wait_for_timeout(2500); shot(pg, '4-person')
    # 訪問：地図に出す → 計画を作る → 計画の中で記録
    pg.goto(B + '?p=visit&go=1&area=今伊勢&no_visit=90'); ok(pg, '事務所から近い順'); pg.wait_for_timeout(3000); shot(pg, '5-visit')
    print('map canvas', pg.locator('#vmap canvas').count(), 'checked', pg.locator('input[name="ids[]"]:checked').count())
    pg.click('button:has-text("選んだ人で訪問計画を作る")'); pg.wait_for_load_state(); ok(pg, '番号は回る順番です'); pg.wait_for_timeout(3000); shot(pg, '6-plan')
    print('plan pins', pg.locator('#plmap .pin').count())
    pg.locator('details.rec summary').first.click(); f = pg.locator('details.rec').first
    f.locator('input[value=absent]').check(); f.locator('button:has-text("訪問を記録する")').click(); pg.wait_for_load_state(); ok(pg, '済み')
    # 陳情・相談：受け付け → 経過 → 段階 → かんばんで動かす
    pg.goto(B + '?p=case_new&person=' + pid); pg.fill('input[name=title]', '試験：通学路の白線が消えている'); pg.fill('textarea[name=body]', '小学校の前の横断歩道。朝は車が多い。'); pg.fill('input[name=due]', '2030-01-31'); pg.click('button:has-text("受け付ける")'); pg.wait_for_load_state()
    ok(pg, '受け付けました'); cid = pg.url.split('id=')[1]
    pg.check('input[name=kind][value=ask]'); pg.fill('form:has(input[value=case_log]) textarea', '市の道路課に電話で照会した'); pg.click('button:has-text("記録する")'); pg.wait_for_load_state(); ok(pg, '市の道路課に電話で照会した')
    if AI:
        pg.click('button:has-text("ここまでを要約する")'); pg.wait_for_load_state(); ok(pg, '【要約】'); print('ai:', pg.locator('.tl li.ai').first.inner_text()[:200].replace('\n', ' '))
    shot(pg, '7-case')
    pg.goto(B + '?p=cases'); ok(pg, 'カードをつかんで'); shot(pg, '8-board')
    card = pg.locator(f'.card[data-id="{cid}"]'); col = pg.locator('.col').nth(1); card.drag_to(col); pg.wait_for_timeout(800)
    pg.goto(B + '?p=case&id=' + cid); st = pg.locator('select[name=stage]').input_value(); print('stage after drag:', st); assert st == '調査中', st
    pg.goto(B + '?p=cases&view=list&state=all&q=白線'); ok(pg, '試験：通学路'); shot(pg, '9-cases-list')
    # 行事：作る → 条件で加える → 出欠
    pg.goto(B + '?p=events'); pg.fill('input[name=name]', '試験の集い'); pg.fill('input[name=on_date]', '2030-02-01'); pg.click('button:has-text("作る")'); pg.wait_for_load_state(); ok(pg, '行事を作りました')
    pg.select_option('form:has(input[value=event_invite]) select[name=area]', '丹陽'); pg.click('button:has-text("この条件の人を加える")'); pg.wait_for_load_state(); ok(pg, '人を案内の対象に加えました')
    pg.locator('select[name=status]').first.select_option('yes'); pg.wait_for_timeout(700); pg.reload(); assert pg.locator('select[name=status]').first.input_value() == 'yes'; shot(pg, '10-event')
    # 担当者は取り込み・書き出しができない
    r = pg.request.get(B + '?p=export&what=people'); assert 'text/csv' not in r.headers.get('content-type', ''), 'member could export'
    # 管理者：CSV 取込（確認 → 取込）・書き出し・設定
    pg.click('button:has-text("ログアウト")'); pg.wait_for_load_state(); pg.locator('button:has-text("管理者")').click(); pg.wait_for_load_state()
    pg.goto(B + '?p=import'); pg.set_input_files('input[name=csv]', CSV); pg.click('button:has-text("確かめる")'); pg.wait_for_load_state(); ok(pg, '取り込む前の確認'); shot(pg, '11-import')
    print('dry:', [x.inner_text().replace('\n', ' ') for x in pg.locator('.kpi').all()])
    pg.click('button:has-text("この内容で取り込む")'); pg.wait_for_load_state(); print('import:', pg.locator('.flash').inner_text())
    r = pg.request.get(B + '?p=export&what=labels&house=1&area=中央'); t = r.body().decode('utf-8-sig'); print('labels:', r.headers.get('content-type'), len(t.splitlines()), 'lines |', t.splitlines()[0]); assert '試験 太郎' in t
    r = pg.request.get(B + '?p=export&what=cases'); assert 'text/csv' in r.headers['content-type']
    pg.goto(B + '?p=settings'); ok(pg, '操作の記録'); pg.wait_for_timeout(2000); shot(pg, '12-settings')
    pg.click('button:has-text("設定を保存する")'); pg.wait_for_load_state(); ok(pg, '設定を保存しました')
    # スマホ幅ではみ出しを測る
    for w in (320, 360, 390):
        ctx = b.new_context(viewport={'width': w, 'height': 800}); m = ctx.new_page(); m.on('pageerror', lambda e: errs.append(str(e)))
        m.goto(B); sw = m.evaluate('document.documentElement.scrollWidth'); assert sw <= w, f'overflow login {w} {sw}'
        if w == 390: m.screenshot(path=os.path.join(OUT, 'm-login.png'), full_page=True)
        m.locator('button:has-text("として試す")').first.click(); m.wait_for_load_state()
        for path in ('?p=home', '?p=people', '?p=person&id=1', '?p=person_new', '?p=visit&go=1&area=大和', '?p=plan&id=1', '?p=cases', '?p=cases&view=list', '?p=case&id=1', '?p=case_new', '?p=events', '?p=event&id=1', '?p=settings'):
            m.goto(B + path); m.wait_for_timeout(300)
            sw = m.evaluate('document.documentElement.scrollWidth'); print(w, path, sw); assert sw <= w, f'overflow {w} {path} {sw}'
            if w == 390: m.screenshot(path=os.path.join(OUT, 'm-' + path[3:].split('&')[0] + '.png'), full_page=True)
        ctx.close()
    b.close()
assert not errs, errs
print('e2e ok')
