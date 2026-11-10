<?php
/**
 * Kurage 陳情・名簿 — 画面（ログイン・今日・名簿・人・取込・訪問計画・陳情・行事・設定・書き出し）。
 */
declare(strict_types=1);
require __DIR__ . '/kcm_lib.php';

session_start_once();
$p = (string)($_GET['p'] ?? 'home');
$a = (string)($_POST['a'] ?? '');
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
function go(string $q, ?string $msg = null): never { if ($msg) { $_SESSION['flash'] = $msg; } header('Location: ?' . $q); exit; }
function qs(array $over = []): string { return http_build_query(array_filter(array_merge($_GET, $over), fn($v) => $v !== '' && $v !== null)); }
/** 名簿の絞り込み（GET でも POST でも同じ名前） */
function people_filter(array $src): array
{
    $f = [];
    foreach (['q', 'area', 'kind', 'rank', 'sort'] as $k) { if (isset($src[$k]) && is_string($src[$k]) && $src[$k] !== '') { $f[$k] = mb_substr($src[$k], 0, 100); } }
    foreach (['no_visit', 'introducer_id'] as $k) { if ((int)($src[$k] ?? 0) > 0) { $f[$k] = (int)$src[$k]; } }
    foreach (['no_pos'] as $k) { if (!empty($src[$k])) { $f[$k] = 1; } }
    if (!empty($src['next_due'])) { $f['next_due'] = date('Y-m-d', time() + 7 * 86400); }
    return $f;
}

/* ---------- ログイン前 ---------- */
$noUsers = !(int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
if ($a === 'demo_login' && cfg('demo') && csrf_ok()) {
    $s = db()->prepare('SELECT id FROM users WHERE id=?'); $s->execute([(int)($_POST['uid'] ?? 1)]);
    if ($id = $s->fetchColumn()) { session_regenerate_id(true); $_SESSION['uid'] = (int)$id; }
    go('p=home');
}
if ($a === 'login' && csrf_ok()) {
    $err = try_login((string)($_POST['email'] ?? ''), (string)($_POST['pass'] ?? ''));
    if ($err) { go('p=login', $err); }
    go('p=home');
}
if ($a === 'setup' && $noUsers && !cfg('demo') && csrf_ok()) {
    try { $id = create_user((string)$_POST['email'], (string)$_POST['name'], (string)$_POST['pass'], 'admin'); } catch (InvalidArgumentException $e) { go('p=login', $e->getMessage()); }
    if (trim((string)($_POST['office'] ?? '')) !== '') { set_setting('office', trim((string)$_POST['office'])); }
    session_regenerate_id(true); $_SESSION['uid'] = $id; go('p=home', '最初の管理者を作りました。「設定」で利用者と、陳情の段階・分野を整えてください');
}
if ($a === 'logout' && csrf_ok()) { $_SESSION = []; session_destroy(); header('Location: ?p=login'); exit; }

$me = me();
if (!$me) { page_login($noUsers, $flash); exit; }
$uid = (int)$me['id'];
$isAdmin = $me['role'] === 'admin';
daily_backup();

/* ---------- 操作 ---------- */
if ($a !== '') {
    if (!csrf_ok()) { http_response_code(400); exit('画面を読み込み直してから、もう一度操作してください'); }
    $id = (int)($_POST['id'] ?? 0);
    $ajax = !empty($_POST['ajax']);
    $admin = function () use ($isAdmin) { if (!$isAdmin) { throw new RuntimeException('この操作は管理者だけができます'); } };
    try {
        switch ($a) {
            case 'person_add':
                $in = $_POST; $in['introducer_id'] = resolve_person((string)($_POST['introducer'] ?? '')) ?? 0;
                if (empty($_POST['force'])) { $d = find_duplicates(keys_of(person_clean($in))); if ($d) { $_SESSION['dup'] = ['in' => array_intersect_key($_POST, array_flip(array_merge(KCM_PERSON_COLS, ['introducer']))), 'with' => $d]; go('p=person_new'); } }
                $nid = add_person($in, $uid); go('p=person&id=' . $nid, '名簿に加えました');
            case 'person_save':
                $in = $_POST; $in['introducer_id'] = resolve_person((string)($_POST['introducer'] ?? '')) ?? 0;
                if (trim((string)($_POST['introducer'] ?? '')) !== '' && !$in['introducer_id']) { $warn = '（紹介者は名簿から1人に決まらなかったので、空にしました。名簿の番号で入れてください）'; }
                update_person($id, $in, $uid); go('p=person&id=' . $id, '保存しました' . ($warn ?? ''));
            case 'person_pos':
                $pp = person($id) ?: throw new RuntimeException('その人は名簿にありません');
                if (!is_numeric($_POST['lat'] ?? null) || !is_numeric($_POST['lng'] ?? null)) { throw new InvalidArgumentException('地図の上で位置を選んでください'); }
                db()->prepare('UPDATE people SET lat=?, lng=?, updated=? WHERE id=?')->execute([(float)$_POST['lat'], (float)$_POST['lng'], now(), $id]);
                oplog($uid, 'person.pos', 'people', $id); go('p=person&id=' . $id, '地図の位置を直しました');
            case 'person_delete':
                $admin(); person($id) ?: throw new RuntimeException('その人は名簿にありません');
                db()->prepare('UPDATE people SET deleted=? WHERE id=?')->execute([now(), $id]); oplog($uid, 'person.delete', 'people', $id);
                go('p=people', '名簿から外しました（記録は残っています）');
            case 'import':
                $admin();
                $raw = '';
                if (!empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) { if ($_FILES['csv']['size'] > 8 * 1024 * 1024) { throw new InvalidArgumentException('CSV は 8MB までです'); } $raw = (string)file_get_contents($_FILES['csv']['tmp_name']); }
                elseif (!empty($_SESSION['import_raw'])) { $raw = (string)$_SESSION['import_raw']; }
                if ($raw === '') { throw new InvalidArgumentException('CSV のファイルを選んでください'); }
                if (cfg('demo') && strlen($raw) > 200000) { throw new InvalidArgumentException('デモでは 200KB までの CSV で試してください'); }
                $dry = empty($_POST['commit']);
                $r = import_people($raw, $uid, empty($_POST['allow_dup']), $dry);
                if ($dry) { $_SESSION['import_raw'] = strlen($raw) <= 2000000 ? $raw : ''; $_SESSION['import_result'] = $r; go('p=import'); }
                unset($_SESSION['import_raw'], $_SESSION['import_result']);
                go('p=people&sort=new', $r['added'] . '人を取り込みました（重複で飛ばした ' . $r['dup'] . '人）。地図に出すには、名簿の画面の「位置を付ける」を押してください');
            case 'geocode':
                $admin(); $r = geocode_missing(cfg('demo') ? 10 : 25);
                go('p=people&no_pos=1', $r['tried'] ? $r['tried'] . '人の住所を調べ、' . $r['ok'] . '人に位置を付けました。残りがあれば、もう一度押してください' : '位置の無い人はいません');
            case 'visit_add':
                $day = date_or_null($_POST['on'] ?? '') ?? today(); if ($day > today()) { throw new InvalidArgumentException('訪問した日は今日までの日付にしてください'); }
                $planId = (int)($_POST['plan_id'] ?? 0);
                record_visit($id, (string)($_POST['result'] ?? ''), (string)($_POST['memo'] ?? ''), date_or_null($_POST['next_on'] ?? ''), $uid, $day . ' ' . ($day === today() ? date('H:i') : '12:00'), $planId && plan($planId) ? $planId : 0);
                go($planId ? 'p=plan&id=' . $planId : 'p=person&id=' . $id, '訪問を記録しました');
            case 'plan_add':
                // 選ばれた人を、事務所から近い順に並べ直す（位置の無い人は最後）
                $rows = array_values(array_filter(array_map(fn($x) => person((int)$x), array_slice(array_unique((array)($_POST['ids'] ?? [])), 0, KCM_PLAN_MAX + 1))));
                $ord = array_column(route_order($rows, office_pos()), 'id');
                $ord = array_merge($ord, array_diff(array_column($rows, 'id'), $ord));
                $pid = create_plan((string)($_POST['name'] ?? ''), date_or_null($_POST['on_date'] ?? ''), (int)($_POST['user_id'] ?? 0) ?: $uid, $ord, $uid);
                go('p=plan&id=' . $pid, '訪問計画を作りました');
            case 'plan_remove':
                plan($id) ?: throw new RuntimeException('その計画はありません');
                db()->prepare('DELETE FROM plan_items WHERE plan_id=? AND person_id=?')->execute([$id, (int)($_POST['person_id'] ?? 0)]);
                go('p=plan&id=' . $id, '計画から外しました');
            case 'plan_delete':
                $pl = plan($id) ?: throw new RuntimeException('その計画はありません');
                if (!$isAdmin && (int)$pl['created_by'] !== $uid) { throw new RuntimeException('計画を消せるのは、作った人と管理者です'); }
                db()->prepare('UPDATE plans SET deleted=? WHERE id=?')->execute([now(), $id]); oplog($uid, 'plan.delete', 'plans', $id);
                go('p=visit', '訪問計画を消しました（訪問の記録は残っています）');
            case 'case_add':
                $in = $_POST; $in['person_id'] = resolve_person((string)($_POST['person'] ?? '')) ?? 0;
                if (!$in['person_id'] && trim((string)($_POST['requester'] ?? '')) === '') { $in['requester'] = trim((string)($_POST['person'] ?? '')); }
                $cid = create_case($in, $uid); go('p=case&id=' . $cid, '陳情・相談を受け付けました');
            case 'case_save':
                $in = $_POST; $in['person_id'] = resolve_person((string)($_POST['person'] ?? '')) ?? 0;
                update_case($id, $in, $uid); go('p=case&id=' . $id, '保存しました');
            case 'case_stage':
                set_case_stage($id, (string)($_POST['stage'] ?? ''), $uid);
                if ($ajax) { header('Content-Type: application/json'); echo '{"ok":true}'; exit; }
                go('p=case&id=' . $id, '段階を変えました');
            case 'case_log':
                kase($id) ?: throw new RuntimeException('その陳情・相談はありません');
                add_case_log($id, (string)($_POST['kind'] ?? 'memo'), (string)($_POST['body'] ?? ''), $uid); go('p=case&id=' . $id, '経過を記録しました');
            case 'case_ai':
                $c = kase($id) ?: throw new RuntimeException('その陳情・相談はありません');
                if (cfg('demo')) { $k = 'ai_' . date('Ymd'); $nAi = (int)meta($k); if ($nAi >= 60) { throw new RuntimeException('デモの AI は1日の回数に達しました。明日またお試しください'); } meta($k, (string)($nAi + 1)); }
                $t = ($_POST['what'] ?? '') === 'reply' ? "【相談した方への文面の下書き】\n" . ai_case_reply($c) : "【要約】\n" . ai_case_summary($c);
                add_case_log($id, 'ai', $t, $uid); oplog($uid, 'case.ai', 'cases', $id, (string)($_POST['what'] ?? ''));
                go('p=case&id=' . $id, 'AI の下書きを経過に入れました。必ず人が読んで直してから使ってください');
            case 'case_log_delete':
                $s = db()->prepare("SELECT * FROM case_logs WHERE id=? AND kind='ai'"); $s->execute([(int)($_POST['log_id'] ?? 0)]); $l = $s->fetch() ?: throw new RuntimeException('消せるのは AI の下書きだけです');
                db()->prepare('DELETE FROM case_logs WHERE id=?')->execute([$l['id']]); go('p=case&id=' . (int)$l['case_id'], 'AI の下書きを消しました');
            case 'case_delete':
                $admin(); kase($id) ?: throw new RuntimeException('その陳情・相談はありません');
                db()->prepare('UPDATE cases SET deleted=? WHERE id=?')->execute([now(), $id]); oplog($uid, 'case.delete', 'cases', $id); go('p=cases', '消しました（記録は残っています）');
            case 'event_add':
                $eid = create_event((string)($_POST['name'] ?? ''), date_or_null($_POST['on_date'] ?? ''), (string)($_POST['place'] ?? ''), (string)($_POST['note'] ?? ''), $uid);
                go('p=event&id=' . $eid, '行事を作りました。案内する人を名簿の条件で選んでください');
            case 'event_save':
                event($id) ?: throw new RuntimeException('その行事はありません');
                if (trim((string)($_POST['name'] ?? '')) === '') { throw new InvalidArgumentException('行事の名前を入れてください'); }
                db()->prepare('UPDATE events SET name=?, on_date=?, place=?, note=? WHERE id=?')->execute([trim((string)$_POST['name']), date_or_null($_POST['on_date'] ?? ''), trim((string)($_POST['place'] ?? '')), trim((string)($_POST['note'] ?? '')), $id]);
                go('p=event&id=' . $id, '保存しました');
            case 'event_invite':
                event($id) ?: throw new RuntimeException('その行事はありません');
                $f = people_filter($_POST); unset($f['sort']);
                $n = invite_by_filter($id, $f, $uid); go('p=event&id=' . $id, $n . '人を案内の対象に加えました');
            case 'invite_set':
                event($id) ?: throw new RuntimeException('その行事はありません');
                set_invite($id, (int)($_POST['person_id'] ?? 0), (string)($_POST['status'] ?? ''), $uid);
                if ($ajax) { header('Content-Type: application/json'); echo '{"ok":true}'; exit; }
                go('p=event&id=' . $id);
            case 'invite_bulk':
                event($id) ?: throw new RuntimeException('その行事はありません');
                $n = db()->prepare("UPDATE invites SET status='sent', updated=? WHERE event_id=? AND status='none'"); $n->execute([now(), $id]);
                oplog($uid, 'event.sent', 'events', $id, $n->rowCount()); go('p=event&id=' . $id, $n->rowCount() . '人を「案内済み」にしました');
            case 'invite_remove':
                db()->prepare('DELETE FROM invites WHERE event_id=? AND person_id=?')->execute([$id, (int)($_POST['person_id'] ?? 0)]); go('p=event&id=' . $id, '案内の対象から外しました');
            case 'event_delete':
                $admin(); db()->prepare('UPDATE events SET deleted=? WHERE id=?')->execute([now(), $id]); oplog($uid, 'event.delete', 'events', $id); go('p=events', '行事を消しました');
            case 'settings_save':
                $admin();
                $lines = fn(string $k) => array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/u', (string)($_POST[$k] ?? '')) ?: []), fn($x) => $x !== '')));
                $st = $lines('stages'); if (count($st) < 2) { throw new InvalidArgumentException('陳情の段階は2つ以上にしてください'); }
                $used = db()->query('SELECT DISTINCT stage FROM cases WHERE deleted IS NULL')->fetchAll(PDO::FETCH_COLUMN);
                if ($miss = array_diff($used, $st)) { throw new InvalidArgumentException('使われている段階は消せません：' . implode('、', $miss) . '（先に、その段階の陳情を別の段階へ移してください）'); }
                $cl = array_values(array_intersect($lines('closed_stages'), $st)); if (!$cl) { throw new InvalidArgumentException('「終わり」として扱う段階を、段階の中から1つ以上入れてください'); }
                set_setting('stages', $st); set_setting('closed_stages', $cl);
                if ($x = $lines('categories')) { set_setting('categories', $x); }
                if ($x = $lines('kinds')) { set_setting('kinds', $x); }
                set_setting('office', trim((string)($_POST['office'] ?? '')));
                if (is_numeric($_POST['lat'] ?? null) && is_numeric($_POST['lng'] ?? null)) { set_setting('office_pos', [(float)$_POST['lat'], (float)$_POST['lng']]); }
                db()->exec('UPDATE cases SET closed=NULL WHERE deleted IS NULL AND closed IS NOT NULL AND stage NOT IN (' . implode(',', array_map(fn($x) => db()->quote($x), $cl)) . ')');
                db()->prepare('UPDATE cases SET closed=? WHERE deleted IS NULL AND closed IS NULL AND stage IN (' . implode(',', array_map(fn($x) => db()->quote($x), $cl)) . ')')->execute([now()]);
                oplog($uid, 'settings.save'); go('p=settings', '設定を保存しました');
            case 'user_add':
                $admin(); if (cfg('demo')) { throw new RuntimeException('デモでは利用者を増やせません'); }
                $nid = create_user((string)$_POST['email'], (string)$_POST['name'], (string)$_POST['pass'], (string)($_POST['role'] ?? 'member')); oplog($uid, 'user.add', 'users', $nid);
                go('p=settings', '利用者を加えました');
            case 'user_toggle':
                $admin(); if (cfg('demo')) { throw new RuntimeException('デモでは利用者を変えられません'); }
                if ($id === $uid) { throw new InvalidArgumentException('自分自身は無効にできません'); }
                db()->prepare('UPDATE users SET active=1-active WHERE id=?')->execute([$id]); oplog($uid, 'user.toggle', 'users', $id); go('p=settings', '利用者の状態を変えました');
            case 'user_pass':
                if (cfg('demo')) { throw new RuntimeException('デモではパスワードを変えられません'); }
                $target = $isAdmin && $id ? $id : $uid;
                if (mb_strlen((string)($_POST['pass'] ?? '')) < 10) { throw new InvalidArgumentException('パスワードは10文字以上にしてください'); }
                db()->prepare('UPDATE users SET pass_hash=?, fail_count=0, locked_until=NULL WHERE id=?')->execute([password_hash((string)$_POST['pass'], PASSWORD_DEFAULT), $target]); oplog($uid, 'user.pass', 'users', $target);
                go('p=settings', 'パスワードを変えました');
            default:
                throw new RuntimeException('その操作はありません');
        }
    } catch (InvalidArgumentException | RuntimeException $e) {
        if ($ajax) { http_response_code(400); header('Content-Type: application/json'); echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE); exit; }
        $back = (string)($_POST['back'] ?? ''); go(preg_match('/^[a-z_]+(&[a-z_]+=[0-9a-z_]*)*$/', $back) ? 'p=' . $back : 'p=' . (preg_match('/^[a-z_]+$/', $p) ? $p : 'home') . ($id ? '&id=' . $id : ''), $e->getMessage());
    }
}

/* ---------- 書き出し（管理者だけ。書き出したことを残す） ---------- */
if ($p === 'export') {
    if (!$isAdmin) { http_response_code(403); exit('書き出しは管理者だけができます'); }
    $what = (string)($_GET['what'] ?? '');
    $label = function (array $rows, bool $house): array {
        $out = []; $seen = [];
        foreach ($rows as $r) {
            if ($r['addr'] === '') { continue; }
            if ($house && $r['k_addr'] !== '' && isset($seen[$r['k_addr']])) { $out[$seen[$r['k_addr']]][3] = trim($out[$seen[$r['k_addr']]][3] . ' ' . $r['name']); continue; }
            if ($house && $r['k_addr'] !== '') { $seen[$r['k_addr']] = count($out); }
            $out[] = [$r['zip'], $r['addr'], $r['name'], '', '様', $r['area']];
        }
        return $out;
    };
    if ($what === 'people') {
        [$w, $arg] = people_where(people_filter($_GET)); $s = db()->prepare("SELECT p.*, (SELECT name FROM people i WHERE i.id=p.introducer_id) intro FROM people p WHERE $w ORDER BY p.area, p.kana"); $s->execute($arg);
        $rows = $s->fetchAll(); oplog($uid, 'export.people', 'people', null, ['n' => count($rows), 'filter' => people_filter($_GET)]);
        csv_out('meibo-' . date('Ymd') . '.csv', ['番号', '氏名', 'ふりがな', '郵便番号', '住所', '電話番号', '携帯', 'メール', '地区', '区分', '支持度', '紹介者', '備考', '最後に訪問した日', '訪問の回数'],
            array_map(fn($r) => [$r['id'], $r['name'], $r['kana'], $r['zip'], $r['addr'], $r['phone'], $r['mobile'], $r['email'], $r['area'], $r['kind'], $r['rank'], (string)$r['intro'], $r['note'], (string)$r['last_visit'], $r['visit_count']], $rows));
    }
    if ($what === 'labels') {
        [$w, $arg] = people_where(people_filter($_GET)); $s = db()->prepare("SELECT p.* FROM people p WHERE $w ORDER BY p.area, p.k_addr, p.kana"); $s->execute($arg);
        $rows = $label($s->fetchAll(), !empty($_GET['house'])); oplog($uid, 'export.labels', 'people', null, ['n' => count($rows)]);
        csv_out('atena-' . date('Ymd') . '.csv', ['郵便番号', '住所', '氏名', '連名', '敬称', '地区'], $rows);
    }
    if ($what === 'event' && ($e = event((int)($_GET['id'] ?? 0)))) {
        $st = (string)($_GET['status'] ?? ''); $rows = invites_of((int)$e['id'], isset(KCM_INVITE[$st]) ? $st : '');
        oplog($uid, 'export.event', 'events', (int)$e['id'], ['n' => count($rows)]);
        if (!empty($_GET['labels'])) { usort($rows, fn($x, $y) => [$x['area'], $x['k_addr']] <=> [$y['area'], $y['k_addr']]); csv_out('atena-event' . (int)$e['id'] . '.csv', ['郵便番号', '住所', '氏名', '連名', '敬称', '地区'], $label($rows, !empty($_GET['house']))); }
        csv_out('shukketsu-event' . (int)$e['id'] . '.csv', ['氏名', 'ふりがな', '地区', '区分', '電話番号', '携帯', '出欠'], array_map(fn($r) => [$r['name'], $r['kana'], $r['area'], $r['kind'], $r['phone'], $r['mobile'], KCM_INVITE[$r['status']] ?? ''], $rows));
    }
    if ($what === 'cases') {
        $rows = cases_query(['state' => 'all'], 1, 100000)['rows']; oplog($uid, 'export.cases', 'cases', null, ['n' => count($rows)]);
        csv_out('chinjo-' . date('Ymd') . '.csv', ['番号', '受付日', '件名', '相談した方', '分野', '受けた経路', '段階', '担当', '期限', '終わった日', '内容'],
            array_map(fn($c) => [$c['id'], $c['received_on'], $c['title'], case_who($c), $c['category'], $c['channel'], $c['stage'], user_name((int)$c['owner_id']), (string)$c['due'], substr((string)$c['closed'], 0, 10), $c['body']], $rows));
    }
    http_response_code(404); exit('書き出すものが見つかりません');
}

/* ---------- 画面 ---------- */
layout_top($me, $p, $flash);
switch ($p) {
    case 'people':     page_people($isAdmin); break;
    case 'person':     page_person((int)($_GET['id'] ?? 0), $isAdmin); break;
    case 'person_new': page_person_new(); break;
    case 'import':     page_import($isAdmin); break;
    case 'visit':      page_visit($me); break;
    case 'plan':       page_plan((int)($_GET['id'] ?? 0)); break;
    case 'cases':      page_cases($me, $isAdmin); break;
    case 'case':       page_case((int)($_GET['id'] ?? 0), $isAdmin); break;
    case 'case_new':   page_case_new(); break;
    case 'events':     page_events(); break;
    case 'event':      page_event((int)($_GET['id'] ?? 0), $isAdmin); break;
    case 'settings':   page_settings($me, $isAdmin); break;
    default:           page_home($me);
}
layout_bottom();

/* ================= 共通の枠 ================= */

function site_desc(): string { return '議員事務所の名簿・訪問計画・陳情の進み具合・行事の出欠を1つにまとめる業務システムのデモ。支持者・後援会員の名簿をCSVで取り込み、地図で近い順に回る訪問計画を作り、陳情・相談を受付から回答までかんばんで追います。PHPとSQLiteだけで動き、名簿を事務所のサーバーの外に出しません。'; }
function layout_head(string $title): void
{ ?><!doctype html><html lang="ja"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light">
<title><?= h($title) ?></title><?php $u = (string)cfg('public_url'); if ($u !== '' && empty($_SESSION['uid'])): $desc = site_desc(); ?>
<meta name="description" content="<?= h($desc) ?>"><link rel="canonical" href="<?= h($u) ?>"><meta property="og:type" content="website"><meta property="og:site_name" content="Kurage 陳情・名簿"><meta property="og:title" content="<?= h($title) ?>"><meta property="og:description" content="<?= h($desc) ?>"><meta property="og:url" content="<?= h($u) ?>"><meta property="og:image" content="<?= h($u) ?>kcm_ogp.png"><meta property="og:locale" content="ja_JP"><meta name="twitter:card" content="summary_large_image">
<?php else: ?><meta name="robots" content="noindex"><?php endif; ?>
<link rel="icon" href="kcm_favicon.png">
<style>
:root{--ink:#14232b;--sub:#53646f;--line:#d9e3e8;--bg:#f4f8f9;--teal:#0a8f85;--teal-d:#07756d;--soft:#eef8f6;--amber:#b7791f;--red:#c0392b;--blue:#2f6fd1}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.7 "Hiragino Sans","Noto Sans JP",system-ui,sans-serif;overflow-wrap:anywhere}
a{color:var(--teal-d)}header.top{background:#fff;border-bottom:1px solid var(--line);padding:10px 16px;display:flex;flex-wrap:wrap;gap:8px 18px;align-items:center;position:sticky;top:0;z-index:20}
header.top .brand{font-weight:900;text-decoration:none;color:var(--ink);font-size:17px;display:flex;align-items:center;gap:8px}header.top .brand img{width:30px;height:34px;object-fit:contain}header.top .brand small{display:block;font-size:11px;color:var(--sub);font-weight:700}
header.top nav{display:flex;flex-wrap:wrap;gap:4px 14px;font-weight:800;font-size:14px}header.top nav a{text-decoration:none}header.top nav a.on{color:var(--ink);border-bottom:2px solid var(--teal)}
header.top .promo{display:flex;flex-wrap:wrap;gap:4px 12px;font-size:13px;font-weight:800;margin-right:8px}header.top .promo a{text-decoration:none;color:#b45309}
header.top .me{margin-left:auto;flex-wrap:wrap;font-size:13px;color:var(--sub);display:flex;gap:8px;align-items:center}
main{padding:16px;max-width:1400px;margin:0 auto}.demo{background:#fff6dc;border:1px solid #ecd9a6;border-radius:10px;padding:8px 14px;margin:0 0 12px;font-size:13.5px}
.flash{background:var(--soft);border:1px solid #bfe0e6;border-radius:10px;padding:10px 14px;margin:0 0 12px;font-weight:700}
h1{font-size:22px;margin:4px 0 12px}h2{font-size:17px;margin:0 0 10px}h3{font-size:15px;margin:0 0 8px}
.btn{display:inline-block;border:0;border-radius:9px;background:var(--teal);color:#fff;font-weight:800;padding:9px 16px;font-size:14px;cursor:pointer;text-decoration:none;font-family:inherit;line-height:1.4}
.btn.sub{background:#fff;color:var(--teal-d);border:1px solid #bfe0e6}.btn.warn{background:#fff;color:var(--red);border:1px solid #f0c4be}.btn.sm{padding:5px 10px;font-size:12.5px}.btn.big{font-size:17px;padding:14px 24px}
input,select,textarea{font:inherit;border:1px solid var(--line);border-radius:8px;padding:8px 10px;background:#fff;width:100%;color:var(--ink)}textarea{min-height:80px}input[type=checkbox],input[type=radio]{width:auto}
label{display:block;font-size:12.5px;font-weight:800;color:var(--sub);margin:8px 0 3px}
.panel{background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px;margin:0 0 14px}
.grid2{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px}.grid3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.grid4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.kpi{background:#fff;border:1px solid var(--line);border-radius:14px;padding:12px 16px;text-decoration:none;color:var(--ink);display:block}.kpi b{display:block;font-size:30px;line-height:1.1;color:var(--teal-d)}.kpi span{font-size:12.5px;color:var(--sub);font-weight:700}.kpi.bad b{color:var(--red)}
table.t{width:100%;border-collapse:collapse;font-size:14px}table.t th,table.t td{border-bottom:1px solid var(--line);padding:7px 8px;text-align:left;vertical-align:top}table.t th{white-space:nowrap;font-size:12.5px;color:var(--sub)}.wide{overflow-x:auto}.wide table.t{min-width:640px}
.tag{display:inline-block;border-radius:999px;padding:1px 9px;font-size:11.5px;font-weight:800;white-space:nowrap;background:#eef2f5;color:#4a5a66}
.tag.over{background:#fde7e4;color:var(--red)}.tag.soon{background:#fff2d6;color:#7a5200}.tag.ok{background:#dff3ee;color:#0a6b63}.tag.rA{background:#dff3ee;color:#0a6b63}.tag.rB{background:#e9f0ff;color:#2a4f9a}.tag.rC{background:#f1f1f1;color:#666}
.filters{display:flex;flex-wrap:wrap;gap:8px;align-items:end}.filters>div{min-width:110px}.filters input,.filters select{width:auto;min-width:110px;max-width:100%}
.pager{display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:13px;margin:10px 0}.pager a,.pager b{padding:4px 10px;border-radius:8px;border:1px solid var(--line);background:#fff;text-decoration:none}.pager b{background:var(--teal);color:#fff;border-color:var(--teal)}
.results{display:flex;flex-wrap:wrap;gap:8px}.results label{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid var(--line);border-radius:999px;padding:7px 13px;cursor:pointer;margin:0;font-size:14px;color:var(--ink)}.results label:has(input:checked){background:var(--soft);border-color:var(--teal);color:var(--teal-d)}
.board{display:grid;grid-auto-flow:column;grid-auto-columns:minmax(230px,1fr);gap:12px;overflow-x:auto;padding-bottom:10px}
.col{background:#eaf1f3;border-radius:12px;padding:10px;min-height:160px}.col h3{margin:0 0 8px;font-size:14px;display:flex;justify-content:space-between;gap:6px}.col h3 small{color:var(--sub);font-weight:700}.col.over{outline:2px dashed var(--teal)}
.card{display:block;background:#fff;border:1px solid var(--line);border-radius:10px;padding:10px 12px;margin:0 0 8px;text-decoration:none;color:var(--ink);cursor:grab}
.card b{display:block;font-size:14px;line-height:1.45}.card .co{font-size:12.5px;color:var(--sub)}.card .meta{display:flex;flex-wrap:wrap;gap:4px 8px;font-size:12px;margin-top:6px;color:var(--sub)}
.tl{list-style:none;margin:0;padding:0}.tl li{border-left:3px solid var(--line);padding:4px 0 10px 12px;margin-left:4px;white-space:pre-wrap}.tl li.ai{border-left-color:#c9b3f5;background:#faf7ff;border-radius:0 8px 8px 0;padding-right:8px}.tl time{font-size:12px;color:var(--sub);font-weight:800;margin-right:6px}.tl .who{font-size:12px;color:var(--sub)}
.stop{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid var(--line)}.stop:last-child{border-bottom:0}.stop .body{flex:1;min-width:0}.stop.done{opacity:.72}.stop details.rec{margin-top:6px}
.muted{color:var(--sub);font-size:13px}.row{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.dupbox{background:#fff6dc;border:1px solid #ecd9a6;border-radius:10px;padding:10px 14px;margin:0 0 12px}
.hero{display:grid;grid-template-columns:minmax(0,1fr) 260px;gap:16px;align-items:center}.hero img{width:100%;height:auto;object-fit:contain;border-radius:10px}
.map{height:440px;border-radius:12px;border:1px solid var(--line);overflow:hidden;background:#e8eef0}.map.sm{height:260px}
.pin{width:26px;height:26px;border-radius:50%;background:var(--teal);color:#fff;font-weight:900;font-size:12px;display:flex;align-items:center;justify-content:center;border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.35);cursor:pointer}.pin.done{background:#8a9aa3}.pin.home{background:#b45309}
.feat{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.feat>div{border:1px solid var(--line);border-radius:12px;padding:12px 14px;background:#fff}.feat b{display:block;color:var(--teal-d);margin-bottom:2px}
details.rec summary{cursor:pointer;font-weight:800;color:var(--teal-d);font-size:13px}
@media(max-width:900px){.grid2,.grid3,.hero,.feat{grid-template-columns:minmax(0,1fr)}.grid4{grid-template-columns:repeat(2,minmax(0,1fr))}header.top .me{margin-left:0}.filters>div{flex:1 1 130px}.filters input,.filters select{width:100%}.map{height:340px}.hero img{max-width:320px;margin:0 auto}}
@media print{header.top,.demo,.flash,.noprint,.map{display:none!important}body{background:#fff}main{padding:0}.panel{border:0;padding:0}details.rec{display:none}}
</style><?= (string)cfg('track_js') ?></head><body><?php
}
function brand(): string { return '<a class="brand" href="?p=home"><img src="kcm_favicon.png" alt="Kurage" width="30" height="34"><span>' . h(cfg('site')) . '<small>' . h(office_name()) . '</small></span></a>'; }
function layout_top(array $me, string $p, ?string $flash): void
{
    layout_head(cfg('site'));
    $nav = ['home' => '今日', 'people' => '名簿', 'visit' => '訪問', 'cases' => '陳情・相談', 'events' => '行事', 'settings' => '設定'];
    $par = ['person' => 'people', 'person_new' => 'people', 'import' => 'people', 'plan' => 'visit', 'case' => 'cases', 'case_new' => 'cases', 'event' => 'events'];
    echo '<header class="top">' . brand() . '<nav>';
    foreach ($nav as $k => $v) { echo '<a href="?p=' . $k . '"' . ($p === $k || ($par[$p] ?? '') === $k ? ' class="on"' : '') . '>' . h($v) . '</a>'; }
    echo '</nav><div class="me">' . promo_links() . h($me['name']) . '<form method="post" style="margin:0"><input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="a" value="logout"><button class="btn sub sm">ログアウト</button></form></div></header><main>';
    if (cfg('demo')) {
        echo '<div class="demo">これはデモです。氏名・住所・相談の内容はすべて架空で、データは1日で元に戻ります。<b>入れた内容はほかの方にも見えるので、実在の方の情報は入れないでください。</b>'
            . (cfg('buy_url') ? ' <a href="' . h(cfg('buy_url')) . '" target="_blank" rel="noopener"><b>事務所のサーバーに置く一式はこちら</b></a>' : '') . '</div>';
    }
    if ($flash) { echo '<div class="flash">' . h($flash) . '</div>'; }
}
function layout_bottom(): void
{
    echo '</main><script>
document.querySelectorAll("[data-confirm]").forEach(f=>f.addEventListener("submit",e=>{if(!confirm(f.dataset.confirm))e.preventDefault()}));
document.querySelectorAll("select[data-auto]").forEach(s=>s.addEventListener("change",()=>{const f=new FormData(s.form);f.set("ajax","1");fetch(location.href,{method:"POST",body:f}).then(r=>r.json()).then(j=>{s.style.outline=j.ok?"2px solid #0a8f85":"2px solid #c0392b";setTimeout(()=>s.style.outline="",900)}).catch(()=>s.form.submit())}));
</script></body></html>';
}
function promo_links(): string
{
    $l = (array)cfg('nav_links'); if (!$l) { return ''; }
    return '<span class="promo">' . implode('', array_map(fn($x) => '<a href="' . h($x[1]) . '" target="_blank" rel="noopener">' . h($x[0]) . '</a>', $l)) . '</span>';
}
function hidden(string $a, int $id = 0): string { return '<input type="hidden" name="csrf" value="' . h(csrf()) . '"><input type="hidden" name="a" value="' . h($a) . '">' . ($id ? '<input type="hidden" name="id" value="' . $id . '">' : ''); }
function dt(?string $s): string { return $s ? h(substr($s, 0, 16)) : ''; }
function sel(string $name, array $opts, $cur, string $blank = '', string $attr = ''): string
{
    $o = '<select name="' . h($name) . '" ' . $attr . '>' . ($blank !== '' ? '<option value="">' . h($blank) . '</option>' : '');
    foreach ($opts as $k => $v) { $o .= '<option value="' . h((string)$k) . '"' . ((string)$k === (string)$cur ? ' selected' : '') . '>' . h($v) . '</option>'; }
    return $o . '</select>';
}
function same(array $a): array { return array_combine($a, $a) ?: []; }
function user_opts(): array { $o = []; foreach (users() as $u) { $o[(int)$u['id']] = $u['name']; } return $o; }
function rank_tag(string $r): string { return $r !== '' ? '<span class="tag r' . h($r) . '">' . h($r) . '</span>' : ''; }
function due_tag(?string $due, bool $closed = false): string
{
    if (!$due) { return ''; }
    $cls = $closed ? '' : ($due < today() ? ' over' : ($due <= date('Y-m-d', time() + 3 * 86400) ? ' soon' : ''));
    return '<span class="tag' . $cls . '">期限 ' . h(substr($due, 5)) . ($cls === ' over' ? '（過ぎています）' : '') . '</span>';
}
function ago(?string $d): string
{
    if (!$d) { return '<span class="muted">まだ</span>'; }
    $n = (int)floor((strtotime(today()) - strtotime(substr($d, 0, 10))) / 86400);
    return h(substr($d, 0, 10)) . ' <span class="muted">（' . ($n <= 0 ? '今日' : ($n < 60 ? $n . '日前' : intdiv($n, 30) . 'か月前')) . '）</span>';
}
function pager(array $r, array $keep = []): string
{
    if ($r['pages'] <= 1) { return '<div class="pager"><span class="muted">' . number_format($r['total']) . '件</span></div>'; }
    $o = '<div class="pager"><span class="muted">' . number_format($r['total']) . '件</span>';
    $pages = array_unique(array_filter([1, $r['page'] - 2, $r['page'] - 1, $r['page'], $r['page'] + 1, $r['page'] + 2, $r['pages']], fn($x) => $x >= 1 && $x <= $r['pages']));
    $prev = null;
    foreach ($pages as $pg) { if ($prev !== null && $pg - $prev > 1) { $o .= '<span>…</span>'; } $o .= $pg === $r['page'] ? "<b>$pg</b>" : '<a href="?' . h(qs(array_merge($keep, ['page' => $pg]))) . "\">$pg</a>"; $prev = $pg; }
    return $o . '</div>';
}
/** 名簿の絞り込みの入力欄（名簿・訪問・行事で同じもの） */
function filter_fields(array $f, bool $withSort = false): string
{
    $o = '<div><label>氏名・住所・電話</label><input name="q" value="' . h($f['q'] ?? '') . '"></div>'
       . '<div><label>地区</label>' . sel('area', same(array_keys(areas())), $f['area'] ?? '', 'すべて') . '</div>'
       . '<div><label>区分</label>' . sel('kind', same(kinds()), $f['kind'] ?? '', 'すべて') . '</div>'
       . '<div><label>支持度</label>' . sel('rank', KCM_RANKS + ['-' => '未設定'], $f['rank'] ?? '', 'すべて') . '</div>'
       . '<div><label>訪問していない期間</label>' . sel('no_visit', [30 => '1か月以上', 90 => '3か月以上', 180 => '半年以上', 365 => '1年以上'], $f['no_visit'] ?? '', '問わない') . '</div>';
    if ($withSort) { $o .= '<div><label>並び</label>' . sel('sort', ['kana' => 'ふりがな', 'area' => '地区', 'visit' => '訪問が古い順', 'rank' => '支持度', 'new' => '新しく入れた順'], $f['sort'] ?? 'kana') . '</div>'; }
    return $o;
}
/** 地図。$pts=[{id,name,sub,lat,lng,n,done,c}] */
function map_block(string $id, array $pts, array $o = []): string
{
    if (!cfg('map')) { return ''; }
    static $loaded = false; $out = '';
    if (!$loaded) {
        $loaded = true;
        $out .= '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/maplibre-gl/4.7.1/maplibre-gl.css"><script src="https://cdnjs.cloudflare.com/ajax/libs/maplibre-gl/4.7.1/maplibre-gl.js"></script><script>
function kcmMap(id,pts,o){const el=document.getElementById(id);if(!window.maplibregl){el.textContent="地図を読み込めませんでした（ネットワークの設定で地図の配信元が止められている場合があります）";el.style.padding="16px";return}
const c=o.center||(pts[0]?[pts[0].lng,pts[0].lat]:[136.9,35.18]);const m=new maplibregl.Map({container:id,style:"https://tiles.openfreemap.org/styles/positron",center:c,zoom:o.zoom||13,attributionControl:{compact:true}});
m.addControl(new maplibregl.NavigationControl({showCompass:false}));
const pop=p=>{const d=document.createElement("div");const a=document.createElement("a");a.href="?p=person&id="+p.id;a.textContent=p.name;a.style.fontWeight="800";d.appendChild(a);if(p.sub){const s=document.createElement("div");s.textContent=p.sub;s.style.fontSize="12px";d.appendChild(s)}return d};
const b=new maplibregl.LngLatBounds();pts.forEach(p=>b.extend([p.lng,p.lat]));if(o.home)b.extend(o.home);
if(pts.length>1||(pts.length&&o.home))m.fitBounds(b,{padding:40,maxZoom:15,duration:0});
if(o.home){const e=document.createElement("div");e.className="pin home";e.textContent="事";e.title="事務所";new maplibregl.Marker({element:e}).setLngLat(o.home).addTo(m)}
if(o.numbered){pts.forEach(p=>{const e=document.createElement("div");e.className="pin"+(p.done?" done":"");e.textContent=p.n;new maplibregl.Marker({element:e}).setLngLat([p.lng,p.lat]).setPopup(new maplibregl.Popup({offset:16}).setDOMContent(pop(p))).addTo(m)});
m.on("load",()=>{const line=(o.home?[o.home]:[]).concat(pts.map(p=>[p.lng,p.lat]));m.addSource("r",{type:"geojson",data:{type:"Feature",geometry:{type:"LineString",coordinates:line}}});m.addLayer({id:"r",type:"line",source:"r",paint:{"line-color":"#0a8f85","line-width":2.5,"line-dasharray":[2,1.5],"line-opacity":.75}})})}
else if(!o.pick){m.on("load",()=>{m.addSource("p",{type:"geojson",data:{type:"FeatureCollection",features:pts.map((p,i)=>({type:"Feature",properties:{i:i,c:p.c||"#0a8f85"},geometry:{type:"Point",coordinates:[p.lng,p.lat]}}))}});
m.addLayer({id:"p",type:"circle",source:"p",paint:{"circle-radius":6.5,"circle-color":["get","c"],"circle-stroke-color":"#fff","circle-stroke-width":1.5}});
m.on("click","p",e=>{const p=pts[e.features[0].properties.i];new maplibregl.Popup({offset:8}).setLngLat([p.lng,p.lat]).setDOMContent(pop(p)).addTo(m)});m.on("mouseenter","p",()=>m.getCanvas().style.cursor="pointer");m.on("mouseleave","p",()=>m.getCanvas().style.cursor="")})}
if(o.pick){let mk=pts[0]?new maplibregl.Marker({color:"#0a8f85"}).setLngLat([pts[0].lng,pts[0].lat]).addTo(m):null;m.on("click",e=>{if(!mk)mk=new maplibregl.Marker({color:"#0a8f85"}).setLngLat(e.lngLat).addTo(m);else mk.setLngLat(e.lngLat);const f=document.getElementById(o.pick);f.querySelector("[name=lat]").value=e.lngLat.lat.toFixed(6);f.querySelector("[name=lng]").value=e.lngLat.lng.toFixed(6);const bt=f.querySelector("button");if(bt)bt.disabled=false})}
}</script>';
    }
    $j = json_encode(array_values($pts), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $oj = json_encode((object)$o, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    return $out . '<div class="map' . (!empty($o['pick']) ? ' sm' : '') . '" id="' . h($id) . '"></div><script>kcmMap(' . json_encode($id) . ',' . $j . ',' . $oj . ')</script>';
}
function pt(array $r, array $more = []): array { return ['id' => (int)$r['id'], 'name' => $r['name'], 'sub' => $r['addr'], 'lat' => (float)$r['lat'], 'lng' => (float)$r['lng']] + $more; }
function home_lnglat(): ?array { $o = office_pos(); return $o ? [$o[1], $o[0]] : null; }

/* ================= ログイン（公開デモでは紹介のページを兼ねる） ================= */

function page_login(bool $noUsers, ?string $flash): void
{
    layout_head('議員事務所の名簿・訪問計画・陳情管理システム｜' . cfg('site') . (cfg('demo') ? '（デモ）' : ''));
    echo '<header class="top"><a class="brand" href="./"><img src="kcm_favicon.png" alt="Kurage" width="30" height="34"><span>' . h(cfg('site')) . '<small>' . h(cfg('demo') ? '株式会社エクスブリッジ' : office_name()) . '</small></span></a><div class="me">' . promo_links() . '</div></header>';
    echo '<main style="max-width:860px;padding-top:24px"><div class="panel"><div class="hero"><div><h1>' . (cfg('demo') ? '議員事務所の名簿・訪問・陳情を、事務所の中で' : h(cfg('site'))) . '</h1>';
    if ($flash) { echo '<div class="flash">' . h($flash) . '</div>'; }
    if (cfg('demo')) {
        $u = (string)cfg('public_url'); $share = '【Kurage 陳情・名簿】議員事務所の名簿・訪問計画・陳情の進み具合・行事の出欠を1つにまとめる業務システムです。名簿は事務所のサーバーの外に出ません。';
        if ($u !== '') {
            echo '<p class="row noprint" style="margin:0 0 10px"><button type="button" class="btn sub sm" id="cp" data-t="' . h($share . "\n" . $u) . '">紹介文とURLをコピー</button><a class="btn sub sm" target="_blank" rel="noopener" href="https://x.com/intent/post?text=' . h(rawurlencode($share)) . '&url=' . h(rawurlencode($u)) . '">Xで共有</a></p>'
               . '<script>document.getElementById("cp").addEventListener("click",e=>{navigator.clipboard.writeText(e.target.dataset.t).then(()=>{e.target.textContent="コピーしました"})})</script>';
        }
        echo '<p><b>Kurage 陳情・名簿 とは、</b>支持者・後援会員の名簿と、訪問の計画と記録、陳情・相談の進み具合、行事の案内と出欠を1つにまとめる、議員事務所のための業務システムです。事務所のサーバーに置いて使うので、名簿が外のクラウドに出ません。</p></div><img src="kcm_ogp.png" alt="Kurage 陳情・名簿の画面のイメージ" width="1200" height="630"></div>';
        echo '<p class="muted" style="margin-top:12px">下のボタンでそのまま中に入れます。氏名・住所・相談の内容はすべて架空で、データは1日で元に戻ります。</p>';
        foreach (users() as $us) { echo '<form method="post" style="margin:0 0 8px">' . hidden('demo_login') . '<input type="hidden" name="uid" value="' . (int)$us['id'] . '"><button class="btn" style="width:100%">' . h($us['name']) . ' として試す</button></form>'; }
        if (cfg('buy_url')) { echo '<p style="margin:12px 0 0"><a href="' . h(cfg('buy_url')) . '" target="_blank" rel="noopener"><b>事務所のサーバーに置く一式（Kurage App Store）</b></a></p>'; }
        echo '</div><div class="panel"><h2>できること</h2><div class="feat">'
            . '<div><b>名簿</b>支持者・後援会員を CSV で取り込む（Excel の名簿をそのまま。UTF-8／Shift_JIS）。氏名と電話か住所が同じ人は重複として知らせます。同じ住所の家族、紹介した人・された人がつながります。</div>'
            . '<div><b>訪問計画</b>地区・支持度・「3か月会えていない」などで絞り、地図に出して、事務所から近い順に回る順番を作ります。訪問先で、在宅・不在・話した内容をスマホから記録できます。</div>'
            . '<div><b>陳情・相談</b>受付から、行政への照会、回答、完了まで。かんばんで段階を動かし、期限と担当、経過（いつ・だれに・何を聞いたか）を残します。</div>'
            . '<div><b>行事の案内と出欠</b>名簿の条件で案内する人を選び、宛名を世帯ごとに1通で書き出します。出欠を1人ずつ付けられます。</div>'
            . '<div><b>AI の下書き</b>相談の記録から、要約と、相談した方へ送る経過のお知らせの下書きを作ります。AI は事務所の中に置いたものにつなげられます。</div>'
            . '<div><b>事務所の中で完結</b>PHP と SQLite だけで動きます。利用者ごとのログイン、管理者だけの書き出し、操作の記録、毎日のバックアップ。寄附・会費の管理は入れていません。</div></div></div>';
        echo '<div class="panel"><h2>よくある質問</h2>';
        foreach (login_faq() as [$q, $ans]) { echo '<details style="margin:0 0 8px"><summary style="font-weight:800;cursor:pointer">' . h($q) . '</summary><p style="margin:6px 0 0">' . h($ans) . '</p></details>'; }
        echo '</div><p class="muted" style="text-align:center">開発：<a href="https://exbridge.jp/">株式会社エクスブリッジ</a></p>' . login_jsonld() . '</main></body></html>'; return;
    }
    echo '<p class="muted">' . h(office_name()) . '</p></div><img src="kcm_ogp.png" alt="" width="1200" height="630"></div>';
    if ($noUsers) {
        echo '<h2>最初の管理者を作る</h2><form method="post">' . hidden('setup') . '<label>事務所名</label><input name="office"><label>名前</label><input name="name"><label>メールアドレス</label><input name="email" type="email" required><label>パスワード（10文字以上）</label><input name="pass" type="password" required minlength="10"><p><button class="btn">作る</button></p></form>';
    } else {
        echo '<form method="post">' . hidden('login') . '<label>メールアドレス</label><input name="email" type="email" required autocomplete="username"><label>パスワード</label><input name="pass" type="password" required autocomplete="current-password"><p><button class="btn">ログイン</button></p></form>';
    }
    echo '</div></main></body></html>';
}
function login_faq(): array
{
    return [
        ['Kurage 陳情・名簿とは何ですか？', '議員事務所の名簿（支持者・後援会員）と、訪問の計画と記録、陳情・相談の進み具合、行事の案内と出欠を1つにまとめる業務システムです。PHPとSQLiteだけで動き、事務所のサーバーやレンタルサーバーにフォルダごと置いて使います。'],
        ['名簿はどこに保存されますか？', '置いたサーバーの中の1つのファイル（SQLite）に保存されます。外へ出る通信は、地図の画像の読み込みと、住所から地図の位置を調べる問い合わせ（住所の文字だけを送ります）の2つで、どちらも設定で切れます。AIは、設定した接続先にだけ相談の記録を送ります。'],
        ['今のExcelの名簿は入れられますか？', '入れられます。CSVで保存して取り込みます。見出しの行から「氏名」「住所」「電話番号」「地区」などの列を探し、取り込む前に、何人入るか・重複が何人あるかを確かめられます。'],
        ['同じ家の家族は重複になりませんか？', 'なりません。重複とするのは、氏名が同じで、電話か住所が同じ人だけです。家族は住所と固定電話が同じでも氏名が違うので、別の人として入り、「同じ住所の人」としてつながります。'],
        ['訪問の順番はどう決めていますか？', '事務所（設定した出発点）から、いちばん近い人へ、を繰り返して並べます。距離は直線で測っており、道のりや一方通行は考えていません。順番を見て、人を外したり足したりできます。'],
        ['選挙のときの戸別訪問にも使えますか？', '選挙運動のための戸別訪問は公職選挙法で禁じられています。このシステムの訪問の記録は、ふだんの後援会活動やあいさつ回り、相談を受けた方への報告のためのものです。'],
        ['寄附や会費の管理はできますか？', '入れていません。政治資金の収支は、専用の会計の仕組みで管理するものと考え、名簿・訪問・陳情・行事に絞っています。'],
        ['AIは何をしますか？', '陳情・相談の記録をもとに、要約と、相談した方へ送る経過のお知らせの下書きを作ります。下書きは経過の欄に入るので、人が読んで直してから使います。AIの接続先を設定しなければ、AIのボタンは出ません。'],
    ];
}
function login_jsonld(): string
{
    $u = (string)cfg('public_url'); if ($u === '') { return ''; }
    $app = ['@context' => 'https://schema.org', '@type' => 'SoftwareApplication', 'name' => 'Kurage 陳情・名簿', 'applicationCategory' => 'BusinessApplication', 'operatingSystem' => 'Web（PHP 8 + SQLite）',
            'url' => $u, 'image' => $u . 'kcm_ogp.png', 'inLanguage' => 'ja', 'description' => '議員事務所の名簿・訪問計画・陳情の進み具合・行事の出欠を1つにまとめる業務システム。事務所のサーバーに置いて使う。',
            'publisher' => ['@type' => 'Organization', 'name' => '株式会社エクスブリッジ', 'url' => 'https://exbridge.jp/']];
    $faq = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($x) => ['@type' => 'Question', 'name' => $x[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $x[1]]], login_faq())];
    $o = ''; foreach ([$app, $faq] as $j) { $o .= '<script type="application/ld+json">' . json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>'; }
    return $o;
}

/* ================= 今日 ================= */

function page_home(array $me): void
{
    $uid = (int)$me['id']; $t = today(); $d = db();
    $nPeople = (int)$d->query('SELECT COUNT(*) FROM people WHERE deleted IS NULL')->fetchColumn();
    $nVisit = (int)$d->query("SELECT COUNT(*) FROM visits WHERE at >= '" . date('Y-m-01') . "'")->fetchColumn();
    $nOpen = (int)$d->query('SELECT COUNT(*) FROM cases WHERE deleted IS NULL AND closed IS NULL')->fetchColumn();
    $nOver = (int)$d->query("SELECT COUNT(*) FROM cases WHERE deleted IS NULL AND closed IS NULL AND due IS NOT NULL AND due < '$t'")->fetchColumn();
    echo '<h1>今日　<span class="muted" style="font-weight:700">' . h($t) . '　' . h($me['name']) . '</span></h1>';
    echo '<div class="grid4"><a class="kpi" href="?p=people"><b>' . number_format($nPeople) . '</b><span>名簿の人数</span></a><a class="kpi" href="?p=visit"><b>' . number_format($nVisit) . '</b><span>今月の訪問</span></a>'
       . '<a class="kpi" href="?p=cases"><b>' . $nOpen . '</b><span>対応中の陳情・相談</span></a><a class="kpi' . ($nOver ? ' bad' : '') . '" href="?p=cases&view=list&overdue=1"><b>' . $nOver . '</b><span>期限を過ぎた陳情・相談</span></a></div>';
    echo '<p class="row" style="margin:14px 0"><a class="btn" href="?p=case_new">陳情・相談を受け付ける</a><a class="btn sub" href="?p=visit">訪問計画を作る</a><a class="btn sub" href="?p=person_new">名簿に1人加える</a></p>';
    $soon = date('Y-m-d', time() + 7 * 86400);
    $cs = $d->query("SELECT c.*, p.name person_name FROM cases c LEFT JOIN people p ON p.id=c.person_id WHERE c.deleted IS NULL AND c.closed IS NULL AND c.due IS NOT NULL AND c.due <= '$soon' ORDER BY c.due LIMIT 12")->fetchAll();
    echo '<div class="grid2"><div class="panel"><h2>期限が近い陳情・相談（7日以内）</h2>';
    if (!$cs) { echo '<p class="muted">ありません。</p>'; } else {
        echo '<table class="t">';
        foreach ($cs as $c) { echo '<tr><td style="white-space:nowrap">' . due_tag($c['due']) . '</td><td><a href="?p=case&id=' . (int)$c['id'] . '">' . h($c['title']) . '</a><br><span class="muted">' . h(case_who($c)) . '　' . h($c['stage']) . '　担当 ' . h(user_name((int)$c['owner_id'])) . '</span></td></tr>'; }
        echo '</table>';
    }
    echo '</div><div class="panel"><h2>訪問計画</h2>';
    $pls = plans_list(true);
    if (!$pls) { echo '<p class="muted">これからの計画はありません。<a href="?p=visit">訪問計画を作る</a></p>'; } else {
        echo '<table class="t">';
        foreach (array_slice($pls, 0, 8) as $pl) { echo '<tr><td style="white-space:nowrap">' . h($pl['on_date'] ?: '日付なし') . ($pl['on_date'] === $t ? ' <span class="tag ok">今日</span>' : '') . '</td><td><a href="?p=plan&id=' . (int)$pl['id'] . '">' . h($pl['name']) . '</a><br><span class="muted">' . (int)$pl['done_n'] . ' / ' . (int)$pl['n'] . '人　' . h(user_name((int)$pl['user_id'])) . '</span></td></tr>'; }
        echo '</table>';
    }
    echo '</div></div><div class="grid2"><div class="panel"><h2>また伺う約束の日が来ている方</h2>';
    $nx = $d->query("SELECT * FROM people WHERE deleted IS NULL AND next_visit IS NOT NULL AND next_visit <= '$soon' ORDER BY next_visit LIMIT 12")->fetchAll();
    if (!$nx) { echo '<p class="muted">ありません。</p>'; } else {
        echo '<table class="t">';
        foreach ($nx as $r) { echo '<tr><td style="white-space:nowrap"><span class="tag' . ($r['next_visit'] < $t ? ' over' : ' soon') . '">' . h(substr($r['next_visit'], 5)) . '</span></td><td><a href="?p=person&id=' . (int)$r['id'] . '">' . h($r['name']) . '</a> <span class="muted">' . h($r['area']) . '　前回 ' . h($r['last_result']) . '</span></td></tr>'; }
        echo '</table><p style="margin:8px 0 0"><a href="?p=visit&next_due=1">この方々で訪問計画を作る</a></p>';
    }
    echo '</div><div class="panel"><h2>これからの行事</h2>';
    $ev = array_filter(events_list(), fn($e) => !$e['on_date'] || $e['on_date'] >= $t);
    if (!$ev) { echo '<p class="muted">ありません。</p>'; } else {
        echo '<table class="t">';
        foreach (array_reverse($ev) as $e) { echo '<tr><td style="white-space:nowrap">' . h($e['on_date'] ?: '日付なし') . '</td><td><a href="?p=event&id=' . (int)$e['id'] . '">' . h($e['name']) . '</a><br><span class="muted">案内 ' . (int)$e['n'] . '人　出席 ' . (int)$e['yes_n'] . '人</span></td></tr>'; }
        echo '</table>';
    }
    echo '</div></div>';
}

/* ================= 名簿 ================= */

function page_people(bool $isAdmin): void
{
    $f = people_filter($_GET); $r = people_query($f, (int)($_GET['page'] ?? 1));
    $noPos = (int)db()->query("SELECT COUNT(*) FROM people WHERE deleted IS NULL AND lat IS NULL AND addr<>''")->fetchColumn();
    echo '<div class="row" style="justify-content:space-between"><h1>名簿</h1><div class="row"><a class="btn" href="?p=person_new">1人加える</a>' . ($isAdmin ? '<a class="btn sub" href="?p=import">CSV で取り込む</a>' : '') . '</div></div>';
    echo '<form class="panel filters" method="get"><input type="hidden" name="p" value="people">' . filter_fields($f, true) . '<div><button class="btn">絞り込む</button> <a class="btn sub" href="?p=people">解除</a></div></form>';
    $fq = http_build_query(array_diff_key($f, ['sort' => 1]));
    echo '<div class="row" style="justify-content:space-between">' . pager($r) . '<div class="row">';
    echo '<a class="btn sub sm" href="?p=visit&' . h($fq) . '">この条件で訪問計画を作る</a>';
    if ($isAdmin) {
        echo '<a class="btn sub sm" href="?p=export&what=people&' . h($fq) . '">CSV で書き出す</a><a class="btn sub sm" href="?p=export&what=labels&house=1&' . h($fq) . '">宛名を書き出す（世帯で1通）</a>';
        if ($noPos && cfg('geocode')) { echo '<form method="post" style="margin:0">' . hidden('geocode') . '<button class="btn sub sm">位置を付ける（地図に無い ' . $noPos . '人）</button></form>'; }
    }
    echo '</div></div><div class="panel wide"><table class="t"><tr><th>氏名</th><th>地区</th><th>住所</th><th>電話</th><th>区分</th><th>支持度</th><th>最後の訪問</th></tr>';
    foreach ($r['rows'] as $x) {
        echo '<tr><td><a href="?p=person&id=' . (int)$x['id'] . '"><b>' . h($x['name']) . '</b></a><br><span class="muted">' . h($x['kana']) . '</span></td><td>' . h($x['area']) . '</td><td>' . h($x['addr']) . ($x['lat'] === null && $x['addr'] !== '' ? ' <span class="tag">地図に無い</span>' : '') . '</td><td style="white-space:nowrap">' . h($x['phone']) . ($x['mobile'] !== '' ? '<br>' . h($x['mobile']) : '') . '</td><td>' . h($x['kind']) . '</td><td>' . rank_tag($x['rank']) . '</td><td>' . ago($x['last_visit']) . ($x['last_result'] !== '' ? '<br><span class="muted">' . h($x['last_result']) . '</span>' : '') . '</td></tr>';
    }
    if (!$r['rows']) { echo '<tr><td colspan="7" class="muted">当てはまる人がいません。</td></tr>'; }
    echo '</table></div>' . pager($r);
}
function person_form(array $t, string $intro = ''): string
{
    $areas = '<datalist id="areas">' . implode('', array_map(fn($x) => '<option value="' . h($x) . '">', array_keys(areas()))) . '</datalist>';
    return '<div class="grid2"><div><label>氏名（必須）</label><input name="name" required value="' . h($t['name'] ?? '') . '"></div><div><label>ふりがな</label><input name="kana" value="' . h($t['kana'] ?? '') . '"></div></div>'
        . '<div class="grid2"><div><label>郵便番号</label><input name="zip" value="' . h($t['zip'] ?? '') . '" inputmode="numeric"></div><div><label>地区（町内・支部など）</label><input name="area" list="areas" value="' . h($t['area'] ?? '') . '">' . $areas . '</div></div>'
        . '<label>住所</label><input name="addr" value="' . h($t['addr'] ?? '') . '">'
        . '<div class="grid2"><div><label>電話番号（自宅）</label><input name="phone" value="' . h($t['phone'] ?? '') . '" inputmode="tel"></div><div><label>携帯</label><input name="mobile" value="' . h($t['mobile'] ?? '') . '" inputmode="tel"></div></div>'
        . '<label>メール</label><input name="email" type="email" value="' . h($t['email'] ?? '') . '">'
        . '<div class="grid2"><div><label>区分</label>' . sel('kind', same(kinds()), $t['kind'] ?? '', '（なし）') . '</div><div><label>支持度</label>' . sel('rank', KCM_RANKS, $t['rank'] ?? '', '（なし）') . '</div></div>'
        . '<label>紹介者（名簿の氏名か、名簿の番号）</label><input name="introducer" value="' . h($intro) . '">'
        . '<label>備考</label><textarea name="note">' . h($t['note'] ?? '') . '</textarea>';
}
function page_person_new(): void
{
    $dup = $_SESSION['dup'] ?? null; unset($_SESSION['dup']); $t = $dup['in'] ?? [];
    echo '<h1>名簿に1人加える</h1>';
    if ($dup) {
        echo '<div class="dupbox"><b>同じ人がすでに名簿にあるようです。</b><ul style="margin:6px 0">';
        foreach ($dup['with'] as $d) { echo '<li><a href="?p=person&id=' . (int)$d['id'] . '">' . h($d['name']) . '</a>　' . h($d['addr']) . '　' . h($d['phone'] ?: $d['mobile']) . '</li>'; }
        echo '</ul>別の人であれば、下の「重複でも加える」を押してください。</div>';
    }
    echo '<form method="post" class="panel" style="max-width:760px">' . hidden('person_add') . '<input type="hidden" name="back" value="person_new">' . person_form($t, (string)($t['introducer'] ?? ''))
        . '<p class="row"><button class="btn">名簿に加える</button>' . ($dup ? '<button class="btn sub" name="force" value="1">重複でも加える</button>' : '') . '<a class="btn sub" href="?p=people">やめる</a></p>'
        . (cfg('geocode') ? '<p class="muted">住所を入れると、地図の位置を調べて付けます（住所の文字だけを国土地理院の住所検索に送ります）。</p>' : '') . '</form>';
}
function visit_form(int $personId, int $planId = 0): string
{
    $o = '<form method="post">' . hidden('visit_add', $personId) . ($planId ? '<input type="hidden" name="plan_id" value="' . $planId . '"><input type="hidden" name="back" value="plan&id=' . $planId . '">' : '') . '<div class="results">';
    foreach (KCM_VISIT_RESULTS as $k => $v) { $o .= '<label><input type="radio" name="result" value="' . $k . '" required>' . h($v) . '</label>'; }
    return $o . '</div><label>話した内容・気づいたこと</label><textarea name="memo"></textarea><div class="grid2"><div><label>訪問した日</label><input type="date" name="on" value="' . today() . '" max="' . today() . '"></div><div><label>また伺う約束の日（あれば）</label><input type="date" name="next_on"></div></div><p style="margin:10px 0 0"><button class="btn">訪問を記録する</button></p></form>';
}
function page_person(int $id, bool $isAdmin): void
{
    $t = person($id);
    if (!$t) { echo '<p>その人は名簿にありません。<a href="?p=people">名簿へ</a></p>'; return; }
    $intro = $t['introducer_id'] ? person((int)$t['introducer_id']) : null;
    echo '<p class="muted" style="margin:0"><a href="?p=people">名簿</a> ›</p><div class="row" style="justify-content:space-between"><h1>' . h($t['name']) . ' <span class="muted" style="font-weight:700">' . h($t['kana']) . '　名簿の番号 ' . (int)$t['id'] . '</span></h1><div class="row"><a class="btn sub sm" href="?p=case_new&person=' . (int)$t['id'] . '">この方の陳情・相談を受け付ける</a></div></div>';
    echo '<div class="grid2"><div><div class="panel"><h2>訪問を記録する</h2>' . visit_form($id) . '</div><div class="panel"><h2>訪問の記録 <span class="muted">' . (int)$t['visit_count'] . '回</span></h2>';
    $vs = visits_of($id);
    if (!$vs) { echo '<p class="muted">まだありません。</p>'; } else {
        echo '<ul class="tl">';
        foreach ($vs as $v) { echo '<li><time>' . dt($v['at']) . '</time><span class="tag' . (in_array($v['result'], ['met', 'family'], true) ? ' ok' : '') . '">' . h(KCM_VISIT_RESULTS[$v['result']] ?? $v['result']) . '</span> <span class="who">' . h(user_name((int)$v['user_id'])) . '</span>' . ($v['memo'] !== '' ? "\n" . h($v['memo']) : '') . ($v['next_on'] ? "\n" . '<span class="muted">また伺う約束：' . h($v['next_on']) . '</span>' : '') . '</li>'; }
        echo '</ul>';
    }
    echo '</div><div class="panel"><h2>陳情・相談</h2>';
    $cs = cases_query(['person_id' => $id, 'state' => 'all'], 1, 50)['rows'];
    if (!$cs) { echo '<p class="muted">ありません。</p>'; } else { echo '<table class="t">'; foreach ($cs as $c) { echo '<tr><td style="white-space:nowrap">' . h($c['received_on']) . '</td><td><a href="?p=case&id=' . (int)$c['id'] . '">' . h($c['title']) . '</a></td><td><span class="tag' . ($c['closed'] ? ' ok' : '') . '">' . h($c['stage']) . '</span></td></tr>'; } echo '</table>'; }
    $s = db()->prepare('SELECT e.id, e.name, e.on_date, i.status FROM invites i JOIN events e ON e.id=i.event_id AND e.deleted IS NULL WHERE i.person_id=? ORDER BY e.on_date DESC LIMIT 30'); $s->execute([$id]); $ev = $s->fetchAll();
    echo '</div><div class="panel"><h2>行事</h2>';
    if (!$ev) { echo '<p class="muted">案内した行事はありません。</p>'; } else { echo '<table class="t">'; foreach ($ev as $e) { echo '<tr><td style="white-space:nowrap">' . h((string)$e['on_date']) . '</td><td><a href="?p=event&id=' . (int)$e['id'] . '">' . h($e['name']) . '</a></td><td><span class="tag' . (in_array($e['status'], ['yes', 'came'], true) ? ' ok' : '') . '">' . h(KCM_INVITE[$e['status']] ?? '') . '</span></td></tr>'; } echo '</table>'; }
    echo '</div></div><div><form method="post" class="panel">' . hidden('person_save', $id) . '<h2>この方の情報</h2>' . person_form($t, $intro ? $intro['name'] . '' : '') . ($intro ? '<p class="muted" style="margin:4px 0 0">紹介者：<a href="?p=person&id=' . (int)$intro['id'] . '">' . h($intro['name']) . '</a>（同じ氏名の人が複数いるときは、名簿の番号 ' . (int)$intro['id'] . ' を入れてください）</p>' : '') . '<p><button class="btn">保存する</button></p></form>';
    $hh = household($t); $s = db()->prepare('SELECT id,name,area FROM people WHERE deleted IS NULL AND introducer_id=? ORDER BY kana LIMIT 50'); $s->execute([$id]); $kids = $s->fetchAll();
    echo '<div class="panel"><h2>つながり</h2><h3>同じ住所の人</h3>';
    echo $hh ? '<p>' . implode('　', array_map(fn($x) => '<a href="?p=person&id=' . (int)$x['id'] . '">' . h($x['name']) . '</a> ' . rank_tag($x['rank']), $hh)) . '</p>' : '<p class="muted">いません。</p>';
    echo '<h3>この方が紹介した人</h3>' . ($kids ? '<p>' . implode('　', array_map(fn($x) => '<a href="?p=person&id=' . (int)$x['id'] . '">' . h($x['name']) . '</a> <span class="muted">' . h($x['area']) . '</span>', $kids)) . '</p>' : '<p class="muted">いません。</p>') . '</div>';
    if (cfg('map')) {
        echo '<div class="panel"><h2>地図の位置</h2>' . ($t['lat'] === null ? '<p class="muted">位置がまだありません。地図の上で家の場所を押して「位置を決める」を押してください。</p>' : '<p class="muted">ずれているときは、地図の上で正しい場所を押して「位置を直す」を押してください。</p>');
        echo map_block('pmap', $t['lat'] === null ? [] : [pt($t)], ['pick' => 'posform', 'zoom' => $t['lat'] === null ? 12 : 16] + ($t['lat'] === null && home_lnglat() ? ['center' => home_lnglat()] : []));
        echo '<form method="post" id="posform" style="margin-top:8px">' . hidden('person_pos', $id) . '<input type="hidden" name="lat"><input type="hidden" name="lng"><button class="btn sub sm" disabled>' . ($t['lat'] === null ? '位置を決める' : '位置を直す') . '</button></form></div>';
    }
    if ($isAdmin) { echo '<form method="post" data-confirm="この方を名簿から外します。訪問や陳情の記録は残ります。よろしいですか？">' . hidden('person_delete', $id) . '<button class="btn warn sm">名簿から外す</button></form>'; }
    echo '</div></div>';
}
function page_import(bool $isAdmin): void
{
    echo '<p class="muted" style="margin:0"><a href="?p=people">名簿</a> ›</p><h1>CSV で取り込む</h1>';
    if (!$isAdmin) { echo '<p>取り込みは管理者だけができます。</p>'; return; }
    $r = $_SESSION['import_result'] ?? null;
    if ($r) {
        echo '<div class="panel"><h2>取り込む前の確認</h2><div class="grid3"><div class="kpi"><b>' . (int)$r['added'] . '</b><span>新しく入る人</span></div><div class="kpi"><b>' . (int)$r['dup'] . '</b><span>重複（すでに名簿にある人）</span></div><div class="kpi"><b>' . (int)$r['empty'] . '</b><span>氏名が空の行</span></div></div>';
        $names = ['name' => '氏名', 'kana' => 'ふりがな', 'zip' => '郵便番号', 'addr' => '住所', 'phone' => '電話番号', 'mobile' => '携帯', 'email' => 'メール', 'area' => '地区', 'kind' => '区分', 'rank' => '支持度', 'introducer' => '紹介者', 'note' => '備考'];
        echo '<p class="muted" style="margin-top:10px">読み取れた列：' . h(implode('、', array_map(fn($c) => $names[$c] ?? $c, $r['columns']))) . '</p>';
        if ($r['dups']) { echo '<h3>重複として飛ばす人（先頭50人）</h3><div class="wide"><table class="t"><tr><th>行</th><th>CSV の氏名</th><th>名簿にある人</th></tr>'; foreach ($r['dups'] as $d) { echo '<tr><td>' . (int)$d['row'] . '</td><td>' . h($d['name']) . '</td><td><a href="?p=person&id=' . (int)$d['with']['id'] . '">' . h($d['with']['name']) . '</a>　' . h($d['with']['addr']) . '</td></tr>'; } echo '</table></div>'; }
        echo '<form method="post" class="row" style="margin-top:12px">' . hidden('import') . '<input type="hidden" name="commit" value="1"><button class="btn"' . (empty($_SESSION['import_raw']) ? ' disabled' : '') . '>この内容で取り込む</button><a class="btn sub" href="?p=import&reset=1">やり直す</a></form></div>';
        if (isset($_GET['reset'])) { unset($_SESSION['import_result'], $_SESSION['import_raw']); }
        return;
    }
    echo '<form method="post" enctype="multipart/form-data" class="panel" style="max-width:760px">' . hidden('import') . '<label>名簿の CSV（Excel で「CSV」として保存したもの。UTF-8 でも Shift_JIS でも）</label><input type="file" name="csv" accept=".csv,text/csv" required>'
        . '<p class="muted">1行目を見出しとして読みます。見出しの例：氏名・ふりがな・郵便番号・住所・電話番号・携帯・メール・地区・区分・支持度・紹介者・備考（「氏名」だけが必須。列の順番は自由です）。<br>氏名が同じで、電話か住所が同じ人は、重複として飛ばします。まず数だけを確かめ、そのあとで取り込みます。</p><p><button class="btn">確かめる</button></p></form>';
}

/* ================= 訪問 ================= */

function page_visit(array $me): void
{
    $f = people_filter($_GET); unset($f['sort']); $searched = isset($_GET['go']) || $f;
    echo '<h1>訪問</h1><form class="panel filters" method="get"><input type="hidden" name="p" value="visit"><input type="hidden" name="go" value="1">' . filter_fields($f)
        . '<div><label>また伺う約束</label>' . sel('next_due', [1 => '約束の日が7日以内'], !empty($f['next_due']) ? 1 : '', '問わない') . '</div><div><button class="btn">この条件で地図に出す</button></div></form>';
    if ($searched) {
        [$w, $a] = people_where($f); $s = db()->prepare("SELECT p.* FROM people p WHERE $w AND p.lat IS NOT NULL ORDER BY p.last_visit IS NOT NULL, p.last_visit LIMIT 300"); $s->execute($a); $rows = $s->fetchAll();
        $c = db()->prepare("SELECT COUNT(*), SUM(p.lat IS NULL) FROM people p WHERE $w"); $c->execute($a); [$total, $noPos] = $c->fetch(PDO::FETCH_NUM);
        $route = route_order($rows, office_pos()); $km = 0.0;
        echo '<div class="panel"><h2>当てはまる人 ' . number_format((int)$total) . '人' . ((int)$total > 300 ? '（訪問が古い順に300人までを地図に出しています）' : '') . ((int)$noPos ? ' <span class="muted">うち ' . (int)$noPos . '人は地図の位置が無く、ここには出ません</span>' : '') . '</h2>';
        if (!$route) { echo '<p class="muted">地図に出せる人がいません。条件を変えてください。</p></div>'; } else {
            $old = date('Y-m-d', time() - 90 * 86400);
            echo map_block('vmap', array_map(fn($r) => pt($r, ['sub' => $r['addr'] . '　最後の訪問 ' . ($r['last_visit'] ?: 'まだ'), 'c' => !$r['last_visit'] ? '#c0392b' : ($r['last_visit'] < $old ? '#b7791f' : '#0a8f85')]), $route), ['home' => home_lnglat()]);
            echo '<p class="muted" style="margin:6px 0 12px">点の色：<span style="color:#c0392b">●</span> まだ訪問していない　<span style="color:#b7791f">●</span> 3か月以上あいている　<span style="color:#0a8f85">●</span> 3か月以内に訪問</p>';
            echo '<form method="post">' . hidden('plan_add') . '<input type="hidden" name="back" value="visit"><div class="filters" style="margin-bottom:10px"><div style="flex:2 1 220px"><label>計画の名前</label><input name="name" value="' . h(trim(($f['area'] ?? '') . ' ' . date('n/j') . ' の訪問')) . '" style="width:100%"></div><div><label>訪問する日</label><input type="date" name="on_date" value="' . today() . '"></div><div><label>回る人</label>' . sel('user_id', user_opts(), (int)$me['id']) . '</div><div><button class="btn">選んだ人で訪問計画を作る</button></div></div>';
            echo '<p class="muted">事務所から近い順に並べています（直線の距離）。上から ' . min(15, count($route)) . '人に印を付けました。印を付け外しして人を選んでください（1つの計画は ' . KCM_PLAN_MAX . '人まで）。</p><div class="wide"><table class="t"><tr><th></th><th>順</th><th>氏名</th><th>住所</th><th>支持度</th><th>最後の訪問</th></tr>';
            foreach ($route as $i => $r) { echo '<tr><td><input type="checkbox" name="ids[]" value="' . (int)$r['id'] . '"' . ($i < 15 ? ' checked' : '') . ' aria-label="' . h($r['name']) . 'を選ぶ"></td><td>' . ($i + 1) . '</td><td><a href="?p=person&id=' . (int)$r['id'] . '">' . h($r['name']) . '</a></td><td>' . h($r['addr']) . '</td><td>' . rank_tag($r['rank']) . '</td><td>' . ago($r['last_visit']) . '</td></tr>'; }
            echo '</table></div></form></div>';
        }
    }
    echo '<div class="panel"><h2>訪問計画</h2>'; $pls = plans_list();
    if (!$pls) { echo '<p class="muted">まだありません。上で条件を選び、地図に出してから作ります。</p>'; } else {
        echo '<div class="wide"><table class="t"><tr><th>日</th><th>計画</th><th>回る人</th><th>進み具合</th></tr>';
        foreach ($pls as $pl) { echo '<tr><td style="white-space:nowrap">' . h($pl['on_date'] ?: '日付なし') . '</td><td><a href="?p=plan&id=' . (int)$pl['id'] . '">' . h($pl['name']) . '</a></td><td>' . h(user_name((int)$pl['user_id'])) . '</td><td>' . (int)$pl['done_n'] . ' / ' . (int)$pl['n'] . '人</td></tr>'; }
        echo '</table></div>';
    }
    echo '</div>';
}
function page_plan(int $id): void
{
    $pl = plan($id);
    if (!$pl) { echo '<p>その計画はありません。<a href="?p=visit">訪問へ</a></p>'; return; }
    $items = plan_items($id); $done = count(array_filter($items, fn($r) => $r['done']));
    echo '<p class="muted noprint" style="margin:0"><a href="?p=visit">訪問</a> ›</p><div class="row" style="justify-content:space-between"><h1>' . h($pl['name']) . ' <span class="muted" style="font-weight:700">' . h($pl['on_date'] ?: '') . '　' . h(user_name((int)$pl['user_id'])) . '　' . $done . ' / ' . count($items) . '人</span></h1><div class="row noprint"><button class="btn sub sm" onclick="window.print()">紙に印刷する</button><form method="post" style="margin:0" data-confirm="この計画を消します。訪問の記録は残ります。よろしいですか？">' . hidden('plan_delete', $id) . '<button class="btn warn sm">計画を消す</button></form></div></div>';
    $pts = []; foreach ($items as $i => $r) { if ($r['lat'] !== null) { $pts[] = pt($r, ['n' => $i + 1, 'done' => (bool)$r['done']]); } }
    if ($pts) { echo '<div class="panel noprint">' . map_block('plmap', $pts, ['numbered' => true, 'home' => home_lnglat()]) . '<p class="muted" style="margin:6px 0 0">番号は回る順番です（事務所から近い順・直線の距離）。灰色は記録が済んだ方。</p></div>'; }
    $tel = fn(string $n) => $n !== '' ? '<a href="tel:' . h(digits($n)) . '">' . h($n) . '</a>' : '';
    echo '<div class="panel stops">';
    foreach ($items as $i => $r) {
        echo '<div class="stop' . ($r['done'] ? ' done' : '') . '"><span class="pin' . ($r['done'] ? ' done' : '') . '" style="cursor:default">' . ($i + 1) . '</span><div class="body"><a href="?p=person&id=' . (int)$r['id'] . '"><b>' . h($r['name']) . '</b></a> ' . rank_tag($r['rank'])
           . ($r['done'] ? ' <span class="tag ok">済み</span> <span class="muted">' . h($r['last_result']) . '</span>' : '') . '<br>' . h($r['addr'])
           . '<br><span class="muted">' . implode('　', array_filter([$tel($r['phone']), $tel($r['mobile']), h($r['note'])])) . '</span>';
        if (!$r['done']) {
            echo '<br><span class="muted noprint">前回：' . ($r['last_visit'] ? h($r['last_visit']) . '　' . h($r['last_result']) : 'まだ訪問していません') . '</span>'
               . '<details class="rec"><summary>記録する</summary>' . visit_form((int)$r['id'], $id) . '<form method="post" class="noprint" style="margin:10px 0 0">' . hidden('plan_remove', $id) . '<input type="hidden" name="person_id" value="' . (int)$r['id'] . '"><button class="btn sub sm">この方を計画から外す</button></form></details>';
        }
        echo '</div></div>';
    }
    echo '</div>';
}

/* ================= 陳情・相談 ================= */

function case_filter(): array
{
    $f = [];
    foreach (['q', 'stage', 'category', 'state'] as $k) { $v = trim((string)($_GET[$k] ?? '')); if ($v !== '') { $f[$k] = $v; } }
    if ((int)($_GET['owner_id'] ?? 0)) { $f['owner_id'] = (int)$_GET['owner_id']; }
    if (!empty($_GET['overdue'])) { $f['overdue'] = 1; }
    return $f;
}
function page_cases(array $me, bool $isAdmin): void
{
    $f = case_filter(); $view = ($_GET['view'] ?? 'board') === 'list' ? 'list' : 'board';
    echo '<div class="row" style="justify-content:space-between"><h1>陳情・相談</h1><div class="row"><a class="btn" href="?p=case_new">受け付ける</a><a class="btn sub sm" href="?' . h(qs(['view' => $view === 'board' ? 'list' : 'board', 'page' => null])) . '">' . ($view === 'board' ? '一覧で見る' : '段階ごとに見る') . '</a>' . ($isAdmin ? '<a class="btn sub sm" href="?p=export&what=cases">CSV で書き出す</a>' : '') . '</div></div>';
    echo '<form class="panel filters" method="get"><input type="hidden" name="p" value="cases"><input type="hidden" name="view" value="' . $view . '"><div><label>ことば</label><input name="q" value="' . h($f['q'] ?? '') . '" placeholder="件名・内容・相談した方"></div><div><label>分野</label>' . sel('category', same(categories()), $f['category'] ?? '', 'すべて') . '</div><div><label>担当</label>' . sel('owner_id', user_opts(), $f['owner_id'] ?? '', 'すべて') . '</div>';
    if ($view === 'list') { echo '<div><label>段階</label>' . sel('stage', same(stages()), $f['stage'] ?? '', 'すべて') . '</div><div><label>状態</label>' . sel('state', ['open' => '対応中', 'closed' => '終わったもの', 'all' => 'すべて'], $f['state'] ?? 'open') . '</div><div><label>期限</label>' . sel('overdue', [1 => '過ぎているものだけ'], !empty($f['overdue']) ? 1 : '', '問わない') . '</div>'; }
    echo '<div><button class="btn">絞り込む</button> <a class="btn sub" href="?p=cases&view=' . $view . '">解除</a></div></form>';
    if ($view === 'list') {
        $r = cases_query($f, (int)($_GET['page'] ?? 1));
        echo pager($r) . '<div class="panel wide"><table class="t"><tr><th>受付</th><th>件名</th><th>相談した方</th><th>分野</th><th>段階</th><th>担当</th><th>期限</th></tr>';
        foreach ($r['rows'] as $c) { echo '<tr><td style="white-space:nowrap">' . h($c['received_on']) . '</td><td><a href="?p=case&id=' . (int)$c['id'] . '"><b>' . h($c['title']) . '</b></a></td><td>' . h(case_who($c)) . '</td><td>' . h($c['category']) . '</td><td><span class="tag' . ($c['closed'] ? ' ok' : '') . '">' . h($c['stage']) . '</span></td><td>' . h(user_name((int)$c['owner_id'])) . '</td><td>' . due_tag($c['due'], (bool)$c['closed']) . '</td></tr>'; }
        if (!$r['rows']) { echo '<tr><td colspan="7" class="muted">当てはまるものがありません。</td></tr>'; }
        echo '</table></div>' . pager($r);
        return;
    }
    // 段階ごと：終わった段階は直近30日に終わったものだけを出す
    $bf = array_diff_key($f, ['stage' => 1, 'state' => 1, 'overdue' => 1]); $closed = closed_stages(); $since = date('Y-m-d H:i', time() - 30 * 86400);
    echo '<p class="muted" style="margin:0 0 8px">カードをつかんで隣の段階へ動かせます（スマホではカードを開いて段階を選びます）。終わった段階には、この30日に終わったものだけを出しています。</p><div class="board" id="board">';
    foreach (stages() as $st) {
        $rows = cases_query($bf + ['stage' => $st, 'state' => 'all'], 1, 60); $list = $rows['rows'];
        if (in_array($st, $closed, true)) { $list = array_values(array_filter($list, fn($c) => !$c['closed'] || $c['closed'] >= $since)); }
        echo '<div class="col" data-stage="' . h($st) . '"><h3>' . h($st) . ' <small>' . count($list) . ($rows['total'] > 60 ? '+' : '') . '</small></h3>';
        foreach ($list as $c) {
            echo '<a class="card" draggable="true" data-id="' . (int)$c['id'] . '" href="?p=case&id=' . (int)$c['id'] . '"><b>' . h($c['title']) . '</b><span class="co">' . h(case_who($c)) . ($c['person_area'] ? '（' . h($c['person_area']) . '）' : '') . '</span><span class="meta">' . ($c['category'] !== '' ? '<span>' . h($c['category']) . '</span>' : '') . '<span>担当 ' . h(user_name((int)$c['owner_id']) ?: '未定') . '</span>' . due_tag($c['due'], (bool)$c['closed']) . '</span></a>';
        }
        echo '</div>';
    }
    echo '</div><script>
(function(){const csrf=' . json_encode(csrf()) . ';let drag=null;
document.querySelectorAll("#board .card").forEach(c=>{c.addEventListener("dragstart",e=>{drag=c;e.dataTransfer.effectAllowed="move";e.dataTransfer.setData("text/plain",c.dataset.id)});c.addEventListener("dragend",()=>{drag=null;document.querySelectorAll("#board .col").forEach(x=>x.classList.remove("over"))})});
document.querySelectorAll("#board .col").forEach(col=>{col.addEventListener("dragover",e=>{if(drag){e.preventDefault();col.classList.add("over")}});col.addEventListener("dragleave",()=>col.classList.remove("over"));
col.addEventListener("drop",e=>{e.preventDefault();col.classList.remove("over");if(!drag||drag.parentNode===col)return;const card=drag,from=card.parentNode;col.appendChild(card);count();
const f=new FormData();f.set("a","case_stage");f.set("id",card.dataset.id);f.set("stage",col.dataset.stage);f.set("csrf",csrf);f.set("ajax","1");
fetch("?p=cases",{method:"POST",body:f}).then(r=>r.json()).then(j=>{if(!j.ok)throw new Error(j.error||"")}).catch(err=>{from.appendChild(card);count();alert("段階を変えられませんでした。"+(err.message||""))})})});
function count(){document.querySelectorAll("#board .col").forEach(c=>{c.querySelector("h3 small").textContent=c.querySelectorAll(".card").length})}})();
</script>';
}
function case_form(array $c, string $person): string
{
    return '<label>件名（必須）</label><input name="title" required value="' . h($c['title'] ?? '') . '" placeholder="例：〇〇町の交差点にカーブミラーを付けてほしい">'
        . '<label>内容</label><textarea name="body" style="min-height:130px">' . h($c['body'] ?? '') . '</textarea>'
        . '<div class="grid2"><div><label>相談した方（名簿の氏名か、名簿の番号）</label><input name="person" value="' . h($person) . '"></div><div><label>名簿に無い方のとき：お名前・連絡先</label><input name="requester" value="' . h($c['requester'] ?? '') . '"></div></div>'
        . '<div class="grid2"><div><label>分野</label>' . sel('category', same(categories()), $c['category'] ?? '', '（なし）') . '</div><div><label>受けた方法</label>' . sel('channel', same(KCM_CHANNELS), $c['channel'] ?? '', '（なし）') . '</div></div>'
        . '<div class="grid3"><div><label>受け付けた日</label><input type="date" name="received_on" value="' . h($c['received_on'] ?? today()) . '"></div><div><label>回答の期限</label><input type="date" name="due" value="' . h($c['due'] ?? '') . '"></div><div><label>担当</label>' . sel('owner_id', user_opts(), $c['owner_id'] ?? '', '未定') . '</div></div>';
}
function page_case_new(): void
{
    $pid = (int)($_GET['person'] ?? 0); $t = $pid ? person($pid) : null;
    echo '<p class="muted" style="margin:0"><a href="?p=cases">陳情・相談</a> ›</p><h1>陳情・相談を受け付ける</h1><form method="post" class="panel" style="max-width:820px">' . hidden('case_add') . '<input type="hidden" name="back" value="case_new">'
        . case_form(['owner_id' => (int)(me()['id'] ?? 0)], $t ? (string)$t['id'] : '') . ($t ? '<p class="muted" style="margin:4px 0 0">名簿の番号 ' . (int)$t['id'] . ' ＝ ' . h($t['name']) . '（' . h($t['area']) . '）</p>' : '')
        . '<p class="row"><button class="btn">受け付ける</button><a class="btn sub" href="?p=cases">やめる</a></p></form>';
}
function page_case(int $id, bool $isAdmin): void
{
    $c = kase($id);
    if (!$c) { echo '<p>その陳情・相談はありません。<a href="?p=cases">陳情・相談へ</a></p>'; return; }
    $t = $c['person_id'] ? person((int)$c['person_id']) : null;
    echo '<p class="muted" style="margin:0"><a href="?p=cases">陳情・相談</a> ›</p><div class="row" style="justify-content:space-between"><h1>' . h($c['title']) . '</h1><div class="row">' . due_tag($c['due'], (bool)$c['closed'])
        . '<form method="post" style="margin:0" class="row">' . hidden('case_stage', $id) . '<label style="margin:0">段階</label>' . sel('stage', same(stages()), $c['stage'], '', 'data-auto') . '</form></div></div>';
    echo '<p class="muted" style="margin:0 0 12px">相談した方：' . ($t ? '<a href="?p=person&id=' . (int)$t['id'] . '">' . h($t['name']) . '</a>（' . h($t['area']) . '　' . h($t['phone'] ?: $t['mobile']) . '）' : h($c['requester'] ?: '（記入なし）')) . '　受付 ' . h($c['received_on']) . '　担当 ' . h(user_name((int)$c['owner_id']) ?: '未定') . ($c['closed'] ? '　終わった日 ' . dt($c['closed']) : '') . '</p>';
    echo '<div class="grid2"><div><div class="panel"><h2>経過を記録する</h2><form method="post">' . hidden('case_log', $id) . '<div class="results">';
    foreach (array_diff_key(KCM_LOG_KINDS, ['ai' => 1]) as $k => $v) { echo '<label><input type="radio" name="kind" value="' . $k . '"' . ($k === 'memo' ? ' checked' : '') . '>' . h($v) . '</label>'; }
    echo '</div><label>内容</label><textarea name="body" required></textarea><p style="margin:10px 0 0"><button class="btn">記録する</button></p></form></div>';
    if (ai_on()) {
        echo '<div class="panel"><h2>AI に下書きさせる</h2><p class="muted">内容と経過を読んで下書きを作り、下の経過に入れます。そのまま送らず、必ず人が読んで直してください。</p><div class="row"><form method="post" style="margin:0">' . hidden('case_ai', $id) . '<input type="hidden" name="what" value="summary"><button class="btn sub">ここまでを要約する</button></form><form method="post" style="margin:0">' . hidden('case_ai', $id) . '<input type="hidden" name="what" value="reply"><button class="btn sub">相談した方への文面を下書きする</button></form></div>'
            . (cfg('demo') ? '<p class="muted" style="margin:8px 0 0">作るのに10〜30秒かかります。</p>' : '') . '</div>';
    }
    echo '<div class="panel"><h2>経過</h2>'; $logs = case_logs($id);
    if (!$logs) { echo '<p class="muted">まだありません。</p>'; } else {
        echo '<ul class="tl">';
        foreach ($logs as $l) {
            echo '<li' . ($l['kind'] === 'ai' ? ' class="ai"' : '') . '><time>' . dt($l['at']) . '</time><span class="tag">' . h(KCM_LOG_KINDS[$l['kind']] ?? $l['kind']) . '</span> <span class="who">' . h(user_name((int)$l['user_id'])) . '</span>' . "\n" . h($l['body']);
            if ($l['kind'] === 'ai') { echo "\n" . '<form method="post" style="display:inline">' . hidden('case_log_delete', $id) . '<input type="hidden" name="log_id" value="' . (int)$l['id'] . '"><button class="btn sub sm">この下書きを消す</button></form>'; }
            echo '</li>';
        }
        echo '</ul>';
    }
    echo '</div></div><div><form method="post" class="panel">' . hidden('case_save', $id) . '<h2>内容</h2>' . case_form($c, $t ? (string)$t['id'] : '') . ($t ? '<p class="muted" style="margin:4px 0 0">名簿の番号 ' . (int)$t['id'] . ' ＝ ' . h($t['name']) . '</p>' : '') . '<p><button class="btn">保存する</button></p></form>';
    if ($isAdmin) { echo '<form method="post" data-confirm="この陳情・相談を消します。よろしいですか？">' . hidden('case_delete', $id) . '<button class="btn warn sm">この陳情・相談を消す</button></form>'; }
    echo '</div></div>';
}

/* ================= 行事 ================= */

function page_events(): void
{
    echo '<h1>行事</h1><div class="grid2"><div class="panel"><h2>行事</h2>'; $ev = events_list();
    if (!$ev) { echo '<p class="muted">まだありません。</p>'; } else {
        echo '<div class="wide"><table class="t"><tr><th>日</th><th>行事</th><th>案内</th><th>出席</th></tr>';
        foreach ($ev as $e) { echo '<tr><td style="white-space:nowrap">' . h($e['on_date'] ?: '日付なし') . '</td><td><a href="?p=event&id=' . (int)$e['id'] . '"><b>' . h($e['name']) . '</b></a><br><span class="muted">' . h($e['place']) . '</span></td><td>' . (int)$e['n'] . '人</td><td>' . (int)$e['yes_n'] . '人</td></tr>'; }
        echo '</table></div>';
    }
    echo '</div><form method="post" class="panel">' . hidden('event_add') . '<input type="hidden" name="back" value="events"><h2>行事を作る</h2><label>行事の名前（必須）</label><input name="name" required placeholder="例：市政報告会"><div class="grid2"><div><label>日</label><input type="date" name="on_date"></div><div><label>場所</label><input name="place"></div></div><label>備考</label><textarea name="note"></textarea><p><button class="btn">作る</button></p></form></div>';
}
function page_event(int $id, bool $isAdmin): void
{
    $e = event($id);
    if (!$e) { echo '<p>その行事はありません。<a href="?p=events">行事へ</a></p>'; return; }
    $st = (string)($_GET['status'] ?? ''); if (!isset(KCM_INVITE[$st])) { $st = ''; }
    $all = invites_of($id); $cnt = array_count_values(array_column($all, 'status')); $rows = $st === '' ? $all : array_values(array_filter($all, fn($r) => $r['status'] === $st));
    echo '<p class="muted" style="margin:0"><a href="?p=events">行事</a> ›</p><h1>' . h($e['name']) . ' <span class="muted" style="font-weight:700">' . h((string)$e['on_date']) . '　' . h($e['place']) . '</span></h1>';
    echo '<div class="grid4" style="margin-bottom:14px"><a class="kpi" href="?p=event&id=' . $id . '"><b>' . count($all) . '</b><span>案内の対象</span></a><a class="kpi" href="?p=event&id=' . $id . '&status=none"><b>' . (int)($cnt['none'] ?? 0) . '</b><span>まだ案内していない</span></a><a class="kpi" href="?p=event&id=' . $id . '&status=yes"><b>' . (int)($cnt['yes'] ?? 0) . '</b><span>出席の返事</span></a><a class="kpi" href="?p=event&id=' . $id . '&status=came"><b>' . (int)($cnt['came'] ?? 0) . '</b><span>当日出席</span></a></div>';
    echo '<div class="grid2"><form method="post" class="panel">' . hidden('event_invite', $id) . '<h2>名簿の条件で、案内する人を加える</h2><div class="filters">' . filter_fields([]) . '</div><p class="muted">何も選ばないと、名簿の全員が対象になります。すでに対象の人は、そのまま残ります。</p><p><button class="btn">この条件の人を加える</button></p></form>';
    echo '<form method="post" class="panel">' . hidden('event_save', $id) . '<h2>行事の内容</h2><label>行事の名前</label><input name="name" required value="' . h($e['name']) . '"><div class="grid2"><div><label>日</label><input type="date" name="on_date" value="' . h((string)$e['on_date']) . '"></div><div><label>場所</label><input name="place" value="' . h($e['place']) . '"></div></div><label>備考</label><textarea name="note">' . h($e['note']) . '</textarea><p><button class="btn">保存する</button></p></form></div>';
    echo '<div class="panel"><div class="row" style="justify-content:space-between"><h2>案内の対象' . ($st !== '' ? '（' . h(KCM_INVITE[$st]) . '）' : '') . ' <span class="muted">' . count($rows) . '人</span></h2><div class="row">';
    if ((int)($cnt['none'] ?? 0)) { echo '<form method="post" style="margin:0" data-confirm="まだ案内していない ' . (int)$cnt['none'] . '人を「案内済み」にします。よろしいですか？">' . hidden('invite_bulk', $id) . '<button class="btn sub sm">案内状を出した（全員を案内済みにする）</button></form>'; }
    if ($isAdmin && $all) { $q = '?p=export&what=event&id=' . $id . ($st !== '' ? '&status=' . $st : ''); echo '<a class="btn sub sm" href="' . h($q) . '&labels=1&house=1">宛名を書き出す（世帯で1通）</a><a class="btn sub sm" href="' . h($q) . '">出欠の表を書き出す</a>'; }
    echo '</div></div><p class="row" style="margin:0 0 8px">';
    foreach (['' => 'すべて'] + KCM_INVITE as $k => $v) { echo '<a class="tag' . ($k === $st ? ' ok' : '') . '" href="?p=event&id=' . $id . ($k !== '' ? '&status=' . $k : '') . '">' . h($v) . ' ' . ($k === '' ? count($all) : (int)($cnt[$k] ?? 0)) . '</a>'; }
    echo '</p>';
    if (!$rows) { echo '<p class="muted">いません。</p>'; } else {
        echo '<div class="wide"><table class="t"><tr><th>氏名</th><th>地区</th><th>住所</th><th>電話</th><th>出欠</th><th></th></tr>';
        foreach ($rows as $r) {
            echo '<tr><td><a href="?p=person&id=' . (int)$r['id'] . '"><b>' . h($r['name']) . '</b></a> ' . rank_tag($r['rank']) . '</td><td>' . h($r['area']) . '</td><td>' . h($r['addr']) . '</td><td style="white-space:nowrap">' . h($r['phone'] ?: $r['mobile']) . '</td><td><form method="post" style="margin:0">' . hidden('invite_set', $id) . '<input type="hidden" name="person_id" value="' . (int)$r['id'] . '">' . sel('status', KCM_INVITE, $r['status'], '', 'data-auto aria-label="' . h($r['name']) . 'の出欠"') . '</form></td><td><form method="post" style="margin:0">' . hidden('invite_remove', $id) . '<input type="hidden" name="person_id" value="' . (int)$r['id'] . '"><button class="btn sub sm">外す</button></form></td></tr>';
        }
        echo '</table></div>';
    }
    echo '</div>';
    if ($isAdmin) { echo '<form method="post" data-confirm="この行事を消します。よろしいですか？">' . hidden('event_delete', $id) . '<button class="btn warn sm">この行事を消す</button></form>'; }
}

/* ================= 設定 ================= */

function page_settings(array $me, bool $isAdmin): void
{
    echo '<h1>設定</h1>';
    if ($isAdmin) {
        $o = office_pos();
        echo '<form method="post" class="panel" id="setform">' . hidden('settings_save') . '<h2>事務所</h2><label>事務所の名前</label><input name="office" value="' . h(office_name()) . '">';
        if (cfg('map')) {
            echo '<label>事務所の場所（訪問の順番は、ここから近い順に並べます）</label>' . map_block('omap', $o ? [['id' => 0, 'name' => '事務所', 'sub' => '', 'lat' => $o[0], 'lng' => $o[1]]] : [], ['pick' => 'setform', 'zoom' => $o ? 14 : 5])
               . '<input type="hidden" name="lat" value="' . h((string)($o[0] ?? '')) . '"><input type="hidden" name="lng" value="' . h((string)($o[1] ?? '')) . '"><p class="muted" style="margin:6px 0 0">地図の上で事務所の場所を押してから、下の「設定を保存する」を押してください。</p>';
        }
        $ta = fn(string $n, array $v, string $l, string $note = '') => '<div><label>' . $l . '</label><textarea name="' . $n . '" style="min-height:150px">' . h(implode("\n", $v)) . '</textarea>' . ($note ? '<p class="muted" style="margin:4px 0 0">' . $note . '</p>' : '') . '</div>';
        echo '<h2 style="margin-top:18px">選ぶ項目（1行に1つ）</h2><div class="grid2">' . $ta('stages', stages(), '陳情・相談の段階（上から順に並びます）', '使われている段階は消せません。') . $ta('closed_stages', closed_stages(), '「終わり」として扱う段階', 'ここに入れた段階へ動かすと、対応中の数から外れます。')
           . $ta('categories', categories(), '陳情・相談の分野') . $ta('kinds', kinds(), '名簿の区分') . '</div><p><button class="btn">設定を保存する</button></p></form>';
        echo '<div class="panel"><h2>利用者</h2><div class="wide"><table class="t"><tr><th>名前</th><th>メール</th><th>役割</th><th>最後のログイン</th><th></th></tr>';
        foreach (users(false) as $u) {
            echo '<tr><td>' . h($u['name']) . ($u['active'] ? '' : ' <span class="tag over">無効</span>') . '</td><td>' . h($u['email']) . '</td><td>' . ($u['role'] === 'admin' ? '管理者' : '担当者') . '</td><td>' . dt($u['last_login']) . '</td><td>'
               . ((int)$u['id'] !== (int)$me['id'] ? '<form method="post" style="margin:0">' . hidden('user_toggle', (int)$u['id']) . '<button class="btn sub sm">' . ($u['active'] ? '無効にする' : '有効に戻す') . '</button></form>' : '') . '</td></tr>';
        }
        echo '</table></div><form method="post" style="margin-top:12px">' . hidden('user_add') . '<h3>利用者を加える</h3><div class="filters"><div><label>名前</label><input name="name" required></div><div><label>メール</label><input name="email" type="email" required></div><div><label>パスワード（10文字以上）</label><input name="pass" type="password" minlength="10" required autocomplete="new-password"></div><div><label>役割</label>' . sel('role', ['member' => '担当者', 'admin' => '管理者'], 'member') . '</div><div><button class="btn">加える</button></div></div></form>'
           . '<p class="muted" style="margin:10px 0 0">管理者だけができること：設定・利用者・CSV の取り込みと書き出し・宛名の書き出し・名簿や陳情の削除。</p></div>';
    }
    echo '<form method="post" class="panel" style="max-width:520px">' . hidden('user_pass') . '<h2>自分のパスワードを変える</h2><label>新しいパスワード（10文字以上）</label><input name="pass" type="password" minlength="10" required autocomplete="new-password"><p><button class="btn">変える</button></p></form>';
    if ($isAdmin) {
        $ops = db()->query('SELECT * FROM oplog ORDER BY id DESC LIMIT 60')->fetchAll();
        echo '<div class="panel"><h2>操作の記録（新しい順に60件）</h2><p class="muted">取り込み・書き出し・削除・設定の変更を残しています。</p><div class="wide"><table class="t"><tr><th>日時</th><th>だれが</th><th>何を</th><th>対象</th><th>内容</th></tr>';
        foreach ($ops as $x) { echo '<tr><td style="white-space:nowrap">' . dt($x['at'] ?? '') . '</td><td>' . h(user_name((int)($x['user_id'] ?? 0))) . '</td><td>' . h((string)($x['action'] ?? '')) . '</td><td>' . h(trim(($x['tbl'] ?? '') . ' ' . ($x['rid'] ?? ''))) . '</td><td>' . h(mb_strimwidth((string)($x['detail'] ?? ''), 0, 80, '…')) . '</td></tr>'; }
        echo '</table></div><p class="muted" style="margin:8px 0 0">データは毎日、控えを取っています（' . (int)cfg('backup_days') . '日ぶん）。' . h(cfg('site')) . ' ' . KCM_VERSION . '</p></div>';
    }
}
