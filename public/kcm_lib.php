<?php
/**
 * Kurage 陳情・名簿（kchinjomeibo）— 共通部品（DB・ログイン・名簿・訪問・陳情・行事・CSV・AI）。
 *
 * 議員事務所の名簿（支持者・後援会員）と、訪問の計画と記録、陳情・相談の進み具合、行事の案内と出欠を1つにまとめる。
 * PHP 8 と SQLite だけで動く。事務所のサーバーやレンタルサーバーにフォルダごと置けば動く（常駐プロセス・ビルド・Node.js なし）。
 *
 * 守っている線：
 *  - 名簿は事務所の外に出さない。地図のタイルと住所→位置の問い合わせ（切れる）以外に、外へ送るものはない。AI は設定した先にだけ送る。
 *  - 寄附・会費・収支は扱わない（政治資金の帳簿とは分ける）。
 *  - 権限はサーバーで決める（管理者だけ：設定・利用者・CSV の書き出し・削除）。
 *  - 消すのは論理削除。消した・変えた・取り込んだ・書き出したは oplog に残す。
 *  - 同じ人の判定は「氏名が同じ」かつ「電話か住所が同じ」。家族は電話と住所が同じでも氏名が違うので別の人。
 */
declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');

const KCM_VERSION = '1.0.0';
const KCM_SCHEMA = 1;

$KCM = [
    'site'        => 'Kurage 陳情・名簿',
    'office'      => '',                                   // 事務所名（画面の上）
    'db'          => __DIR__ . '/kcm_data/kchinjomeibo.sqlite',
    'idle_min'    => 60,                                   // 無操作でログアウトする分
    'backup_days' => 30,                                   // 毎日のバックアップを残す日数
    'page_size'   => 50,
    'map'         => true,                                 // 地図を使う（タイルを OpenFreeMap から読む）
    'geocode'     => true,                                 // 住所から位置を付ける（国土地理院の住所検索に住所だけを送る）
    'ai_url'      => '',                                   // OpenAI 互換の /v1/chat/completions（空なら AI のボタンを出さない）
    'ai_token'    => '',
    'ai_model'    => 'gemma4',
    'demo'        => false,                                // true: ワンクリックで入れる・データを1日で初期化（当社の公開デモ用。本番は必ず false）
    'public_url'  => '',                                   // 公開デモのURL（空なら noindex）
    'buy_url'     => '',
    'nav_links'   => [],
    'track_js'    => '',
];
if (is_file(__DIR__ . '/kcm_config.php')) { $KCM = array_merge($KCM, (array)require __DIR__ . '/kcm_config.php'); }

const KCM_DEFAULT_KINDS = ['後援会員', '支持者', '紹介者', '団体・企業', 'ボランティア', 'その他'];
const KCM_RANKS = ['A' => 'A', 'B' => 'B', 'C' => 'C'];
// 訪問の結果。met=会えた（最後に会った日を進める）
const KCM_VISIT_RESULTS = ['met' => '本人と話した', 'family' => '家族と話した', 'absent' => '不在', 'left' => '不在（資料を置いた）', 'moved' => '転居・不明'];
const KCM_DEFAULT_STAGES = ['受付', '調査中', '行政へ照会', '回答待ち', '回答済み', '完了'];
const KCM_DEFAULT_CLOSED = ['完了'];
const KCM_DEFAULT_CATEGORIES = ['道路・交通', '福祉・介護', '子育て・教育', '防災・防犯', '産業・雇用', '環境・ごみ', 'その他'];
const KCM_CHANNELS = ['来所', '電話', 'メール', '訪問先で', '行事で', '手紙', 'その他'];
const KCM_LOG_KINDS = ['memo' => 'メモ', 'ask' => '行政へ照会', 'answer' => '行政の回答', 'tell' => '本人へ連絡', 'ai' => 'AIの下書き'];
const KCM_INVITE = ['none' => '未案内', 'sent' => '案内済み', 'yes' => '出席', 'no' => '欠席', 'came' => '当日出席'];
const KCM_PLAN_MAX = 60;

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function cfg(string $k) { global $KCM; return $KCM[$k] ?? null; }
function now(): string { return date('Y-m-d H:i:s'); }
function today(): string { return date('Y-m-d'); }
function valid_date(?string $d): bool { return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4)); }
function date_or_null($v): ?string { $v = trim((string)$v); return valid_date($v) ? $v : null; }

/* ================= 表記ゆれ（同じ人の判定） ================= */

function nfkc(?string $s): string
{
    $s = (string)$s;
    if (class_exists('Normalizer')) { return Normalizer::normalize($s, Normalizer::FORM_KC) ?: $s; }
    return mb_convert_kana($s, 'asKV', 'UTF-8');
}
/** 氏名：空白を外す（「山田 太郎」と「山田太郎」を同じに）。旧字・異体字はそろえない */
function name_key(?string $s): string { return mb_strtolower((string)preg_replace('/[\s　]/u', '', nfkc($s))); }
function digits(?string $s): string { return preg_replace('/\D/u', '', nfkc($s)) ?? ''; }
/** 住所：丁目・番地・号と全角ハイフンをそろえ、都道府県とカッコ内は外す */
function address_key(?string $s): string
{
    $a = preg_replace('/\s/u', '', nfkc($s));
    $a = preg_replace('/\(.*?\)/u', '', (string)$a);
    $a = preg_replace_callback('/[〇一二三四五六七八九十]+/u', function ($m) {
        $k = ['〇' => 0, '一' => 1, '二' => 2, '三' => 3, '四' => 4, '五' => 5, '六' => 6, '七' => 7, '八' => 8, '九' => 9];
        $s = $m[0];
        if ($s === '十') { return '10'; }
        if (preg_match('/^([一二三四五六七八九])?十([一二三四五六七八九])?$/u', $s, $t)) { return (string)(((($t[1] ?? '') !== '') ? $k[$t[1]] : 1) * 10 + ((($t[2] ?? '') !== '') ? $k[$t[2]] : 0)); }
        return implode('', array_map(fn($c) => $k[$c] ?? $c, mb_str_split($s)));
    }, (string)$a);
    $a = preg_replace(['/(\d)ー(\d)/u', '/(\d)の(\d)/u'], '$1-$2', (string)$a);
    $a = preg_replace(['/丁目|番地|番|号/u', '/[‐‑–—−－]/u'], '-', (string)$a);
    $a = preg_replace(['/-+/', '/-$/'], ['-', ''], (string)$a);
    return (string)preg_replace('/^(北海道|東京都|京都府|大阪府|.{2,3}?県)/u', '', (string)$a, 1);
}
function keys_of(array $t): array
{
    return ['k_name' => name_key($t['name'] ?? ''), 'k_phone' => digits($t['phone'] ?? ''), 'k_mobile' => digits($t['mobile'] ?? ''), 'k_addr' => address_key($t['addr'] ?? '')];
}

/* ================= DB ================= */

function db(): PDO
{
    static $pdo = null;
    if ($pdo) { return $pdo; }
    $f = (string)cfg('db');
    if (!is_dir(dirname($f))) { mkdir(dirname($f), 0770, true); }
    $new = !is_file($f);
    $pdo = new PDO('sqlite:' . $f, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $pdo->exec('PRAGMA foreign_keys=ON; PRAGMA journal_mode=WAL; PRAGMA busy_timeout=8000; PRAGMA synchronous=NORMAL;');
    if ($new) { @chmod($f, 0600); }
    schema($pdo);
    if (cfg('demo')) { if ($new) { seed_demo($pdo); } else { demo_reset_if_old($pdo); } }
    return $pdo;
}

function schema(PDO $p): void
{
    $v = (int)$p->query('PRAGMA user_version')->fetchColumn();
    if ($v >= KCM_SCHEMA) { return; }
    $p->exec(<<<SQL
CREATE TABLE IF NOT EXISTS meta(k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE IF NOT EXISTS settings(k TEXT PRIMARY KEY, v TEXT NOT NULL, updated TEXT);
CREATE TABLE IF NOT EXISTS users(
  id INTEGER PRIMARY KEY, email TEXT UNIQUE NOT NULL, name TEXT NOT NULL, pass_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'member', active INTEGER NOT NULL DEFAULT 1,
  fail_count INTEGER NOT NULL DEFAULT 0, locked_until TEXT, last_login TEXT, created TEXT);
CREATE TABLE IF NOT EXISTS people(
  id INTEGER PRIMARY KEY, name TEXT NOT NULL, kana TEXT NOT NULL DEFAULT '', zip TEXT NOT NULL DEFAULT '', addr TEXT NOT NULL DEFAULT '',
  phone TEXT NOT NULL DEFAULT '', mobile TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '',
  area TEXT NOT NULL DEFAULT '', kind TEXT NOT NULL DEFAULT '', rank TEXT NOT NULL DEFAULT '', introducer_id INTEGER, note TEXT NOT NULL DEFAULT '',
  lat REAL, lng REAL, last_visit TEXT, last_met TEXT, last_result TEXT NOT NULL DEFAULT '', visit_count INTEGER NOT NULL DEFAULT 0, next_visit TEXT,
  k_name TEXT NOT NULL DEFAULT '', k_phone TEXT NOT NULL DEFAULT '', k_mobile TEXT NOT NULL DEFAULT '', k_addr TEXT NOT NULL DEFAULT '',
  created TEXT, updated TEXT, deleted TEXT);
CREATE INDEX IF NOT EXISTS pe_kana ON people(deleted, kana);
CREATE INDEX IF NOT EXISTS pe_area ON people(deleted, area, kana);
CREATE INDEX IF NOT EXISTS pe_kname ON people(k_name);
CREATE INDEX IF NOT EXISTS pe_kaddr ON people(k_addr);
CREATE INDEX IF NOT EXISTS pe_visit ON people(deleted, last_visit);
CREATE INDEX IF NOT EXISTS pe_next ON people(deleted, next_visit);
CREATE INDEX IF NOT EXISTS pe_intro ON people(introducer_id);
CREATE TABLE IF NOT EXISTS visits(
  id INTEGER PRIMARY KEY, person_id INTEGER NOT NULL REFERENCES people(id), user_id INTEGER, plan_id INTEGER,
  at TEXT NOT NULL, result TEXT NOT NULL, memo TEXT NOT NULL DEFAULT '', next_on TEXT, created TEXT);
CREATE INDEX IF NOT EXISTS vi_person ON visits(person_id, at);
CREATE INDEX IF NOT EXISTS vi_at ON visits(at, user_id);
CREATE TABLE IF NOT EXISTS plans(
  id INTEGER PRIMARY KEY, name TEXT NOT NULL, on_date TEXT, user_id INTEGER, note TEXT NOT NULL DEFAULT '', created_by INTEGER, created TEXT, deleted TEXT);
CREATE INDEX IF NOT EXISTS pl_date ON plans(deleted, on_date);
CREATE TABLE IF NOT EXISTS plan_items(
  plan_id INTEGER NOT NULL REFERENCES plans(id), person_id INTEGER NOT NULL REFERENCES people(id), seq INTEGER NOT NULL, done TEXT,
  PRIMARY KEY(plan_id, person_id));
CREATE TABLE IF NOT EXISTS cases(
  id INTEGER PRIMARY KEY, title TEXT NOT NULL, body TEXT NOT NULL DEFAULT '', person_id INTEGER, requester TEXT NOT NULL DEFAULT '',
  category TEXT NOT NULL DEFAULT '', channel TEXT NOT NULL DEFAULT '', stage TEXT NOT NULL, owner_id INTEGER,
  received_on TEXT, due TEXT, created TEXT, updated TEXT, closed TEXT, deleted TEXT);
CREATE INDEX IF NOT EXISTS ca_stage ON cases(deleted, stage);
CREATE INDEX IF NOT EXISTS ca_person ON cases(person_id);
CREATE INDEX IF NOT EXISTS ca_due ON cases(deleted, closed, due);
CREATE TABLE IF NOT EXISTS case_logs(
  id INTEGER PRIMARY KEY, case_id INTEGER NOT NULL REFERENCES cases(id), user_id INTEGER, at TEXT NOT NULL, kind TEXT NOT NULL DEFAULT 'memo', body TEXT NOT NULL, created TEXT);
CREATE INDEX IF NOT EXISTS cl_case ON case_logs(case_id, at);
CREATE TABLE IF NOT EXISTS events(
  id INTEGER PRIMARY KEY, name TEXT NOT NULL, on_date TEXT, place TEXT NOT NULL DEFAULT '', note TEXT NOT NULL DEFAULT '', created_by INTEGER, created TEXT, deleted TEXT);
CREATE TABLE IF NOT EXISTS invites(
  event_id INTEGER NOT NULL REFERENCES events(id), person_id INTEGER NOT NULL REFERENCES people(id), status TEXT NOT NULL DEFAULT 'none', note TEXT NOT NULL DEFAULT '', updated TEXT,
  PRIMARY KEY(event_id, person_id));
CREATE INDEX IF NOT EXISTS in_person ON invites(person_id);
CREATE TABLE IF NOT EXISTS oplog(
  id INTEGER PRIMARY KEY, at TEXT NOT NULL, user_id INTEGER, action TEXT NOT NULL, tbl TEXT, rid INTEGER, detail TEXT, ip TEXT);
CREATE INDEX IF NOT EXISTS oplog_at ON oplog(at);
SQL);
    $p->exec('PRAGMA user_version = ' . KCM_SCHEMA);
}

function meta(string $k, ?string $v = null): ?string
{
    if ($v !== null) { db()->prepare('INSERT OR REPLACE INTO meta(k,v) VALUES(?,?)')->execute([$k, $v]); return $v; }
    $s = db()->prepare('SELECT v FROM meta WHERE k=?'); $s->execute([$k]);
    $r = $s->fetchColumn(); return $r === false ? null : (string)$r;
}
$KCM_SETTING_CACHE = [];
function setting(string $k, $default = null)
{
    global $KCM_SETTING_CACHE;
    if (!array_key_exists($k, $KCM_SETTING_CACHE)) {
        $s = db()->prepare('SELECT v FROM settings WHERE k=?'); $s->execute([$k]);
        $r = $s->fetchColumn(); $KCM_SETTING_CACHE[$k] = $r === false ? null : json_decode((string)$r, true);
    }
    return $KCM_SETTING_CACHE[$k] ?? $default;
}
function set_setting(string $k, $v): void
{
    global $KCM_SETTING_CACHE;
    db()->prepare('INSERT OR REPLACE INTO settings(k,v,updated) VALUES(?,?,?)')->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE), now()]);
    unset($KCM_SETTING_CACHE[$k]);
}
function kinds(): array { return (array)setting('kinds', KCM_DEFAULT_KINDS); }
function stages(): array { return (array)setting('stages', KCM_DEFAULT_STAGES); }
function closed_stages(): array { return (array)setting('closed_stages', KCM_DEFAULT_CLOSED); }
function categories(): array { return (array)setting('categories', KCM_DEFAULT_CATEGORIES); }
function office_name(): string { return (string)(setting('office', cfg('office')) ?: cfg('office')); }
function areas(): array { return db()->query("SELECT area, COUNT(*) n FROM people WHERE deleted IS NULL AND area<>'' GROUP BY area ORDER BY area")->fetchAll(PDO::FETCH_KEY_PAIR); }

function oplog(?int $uid, string $action, ?string $tbl = null, ?int $rid = null, $detail = null): void
{
    db()->prepare('INSERT INTO oplog(at,user_id,action,tbl,rid,detail,ip) VALUES(?,?,?,?,?,?,?)')
        ->execute([now(), $uid, $action, $tbl, $rid, $detail === null ? null : (is_string($detail) ? mb_substr($detail, 0, 2000) : json_encode($detail, JSON_UNESCAPED_UNICODE)), $_SERVER['REMOTE_ADDR'] ?? '']);
}

/* ================= ログイン・セッション ================= */

function is_https(): bool { return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'; }
function session_start_once(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    session_name('kchinjomeibo');
    session_set_cookie_params(['lifetime' => 0, 'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    if (!empty($_SESSION['uid']) && (time() - (int)($_SESSION['seen'] ?? 0)) > (int)cfg('idle_min') * 60) { $_SESSION = []; session_regenerate_id(true); }
    $_SESSION['seen'] = time();
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
}
function csrf(): string { return (string)($_SESSION['csrf'] ?? ''); }
function csrf_ok(): bool { return isset($_POST['csrf']) && hash_equals(csrf(), (string)$_POST['csrf']); }
function norm_email(string $e): string { return strtolower(trim($e)); }
function me(): ?array
{
    if (empty($_SESSION['uid'])) { return null; }
    $s = db()->prepare('SELECT * FROM users WHERE id=? AND active=1'); $s->execute([(int)$_SESSION['uid']]);
    return $s->fetch() ?: null;
}
/** 続けて5回間違えたら15分受け付けない */
function try_login(string $email, string $pass): ?string
{
    usleep(250000);
    $s = db()->prepare('SELECT * FROM users WHERE email=?'); $s->execute([norm_email($email)]);
    $u = $s->fetch();
    if ($u && $u['locked_until'] && $u['locked_until'] > now()) { return 'ログインに続けて失敗したため、しばらく受け付けません。15分ほどおいてお試しください'; }
    if (!$u || !password_verify($pass, $u['pass_hash'])) {
        if ($u) { $n = (int)$u['fail_count'] + 1; db()->prepare('UPDATE users SET fail_count=?, locked_until=? WHERE id=?')->execute([$n >= 5 ? 0 : $n, $n >= 5 ? date('Y-m-d H:i:s', time() + 900) : null, $u['id']]); }
        oplog(null, 'login.fail', null, null, norm_email($email));
        return 'メールアドレスかパスワードが違います';
    }
    if (!$u['active']) { return 'このアカウントは無効になっています。管理者にお問い合わせください'; }
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    db()->prepare('UPDATE users SET fail_count=0, locked_until=NULL, last_login=? WHERE id=?')->execute([now(), $u['id']]);
    oplog((int)$u['id'], 'login');
    return null;
}
function users(bool $activeOnly = true): array
{
    return db()->query('SELECT id,email,name,role,active,last_login FROM users' . ($activeOnly ? ' WHERE active=1' : '') . ' ORDER BY id')->fetchAll();
}
function user_name(?int $id): string
{
    static $m = null;
    if ($m === null) { $m = []; foreach (users(false) as $u) { $m[(int)$u['id']] = $u['name']; } }
    return $id ? ($m[$id] ?? '') : '';
}
function create_user(string $email, string $name, string $pass, string $role): int
{
    $email = norm_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new InvalidArgumentException('メールアドレスの形が正しくありません'); }
    if (mb_strlen($pass) < 10) { throw new InvalidArgumentException('パスワードは10文字以上にしてください'); }
    db()->prepare('INSERT INTO users(email,name,pass_hash,role,created) VALUES(?,?,?,?,?)')->execute([$email, trim($name) ?: $email, password_hash($pass, PASSWORD_DEFAULT), $role === 'admin' ? 'admin' : 'member', now()]);
    return (int)db()->lastInsertId();
}

/* ================= 名簿 ================= */

const KCM_PERSON_COLS = ['name', 'kana', 'zip', 'addr', 'phone', 'mobile', 'email', 'area', 'kind', 'rank', 'note'];

function person(int $id): ?array { $s = db()->prepare('SELECT * FROM people WHERE id=? AND deleted IS NULL'); $s->execute([$id]); return $s->fetch() ?: null; }
function person_clean(array $in): array
{
    $o = [];
    foreach (KCM_PERSON_COLS as $c) { $o[$c] = trim((string)($in[$c] ?? '')); }
    $o['kana'] = mb_convert_kana($o['kana'], 'HVc', 'UTF-8');   // ふりがなは ひらがな にそろえる（並べ替えのため）
    $o['rank'] = strtoupper(nfkc($o['rank'])); if (!isset(KCM_RANKS[$o['rank']])) { $o['rank'] = ''; }
    return $o;
}
/** 同じ人：氏名が同じで、電話（自宅か携帯）か住所が同じ */
function find_duplicates(array $k, int $limit = 5, int $exceptId = 0): array
{
    if ($k['k_name'] === '') { return []; }
    $w = []; $a = [$k['k_name'], $exceptId];
    foreach ([$k['k_phone'], $k['k_mobile']] as $ph) { if ($ph !== '') { $w[] = '(k_phone=? OR k_mobile=?)'; $a[] = $ph; $a[] = $ph; } }
    if ($k['k_addr'] !== '') { $w[] = 'k_addr=?'; $a[] = $k['k_addr']; }
    if (!$w) { return []; }
    $s = db()->prepare('SELECT id,name,addr,phone,mobile FROM people WHERE deleted IS NULL AND k_name=? AND id<>? AND (' . implode(' OR ', $w) . ') LIMIT ' . (int)$limit);
    $s->execute($a); return $s->fetchAll();
}
function add_person(array $in, int $uid, bool $geo = true): int
{
    $t = person_clean($in);
    if ($t['name'] === '') { throw new InvalidArgumentException('氏名を入れてください'); }
    $k = keys_of($t);
    $pos = [isset($in['lat']) && is_numeric($in['lat']) ? (float)$in['lat'] : null, isset($in['lng']) && is_numeric($in['lng']) ? (float)$in['lng'] : null];
    if ($pos[0] === null && $geo && $t['addr'] !== '') { $pos = geocode($t['addr']) ?? [null, null]; }
    db()->prepare('INSERT INTO people(' . implode(',', KCM_PERSON_COLS) . ',introducer_id,lat,lng,k_name,k_phone,k_mobile,k_addr,created,updated) VALUES(' . str_repeat('?,', count(KCM_PERSON_COLS)) . '?,?,?,?,?,?,?,?,?)')
        ->execute(array_merge(array_values($t), [isset($in['introducer_id']) && (int)$in['introducer_id'] ? (int)$in['introducer_id'] : null, $pos[0], $pos[1], $k['k_name'], $k['k_phone'], $k['k_mobile'], $k['k_addr'], now(), now()]));
    $id = (int)db()->lastInsertId();
    oplog($uid, 'person.add', 'people', $id);
    return $id;
}
function update_person(int $id, array $in, int $uid): void
{
    $old = person($id); if (!$old) { throw new RuntimeException('その人は名簿にありません'); }
    $t = person_clean($in);
    if ($t['name'] === '') { throw new InvalidArgumentException('氏名を入れてください'); }
    $k = keys_of($t);
    $lat = $old['lat']; $lng = $old['lng'];
    if (isset($in['lat'], $in['lng']) && is_numeric($in['lat']) && is_numeric($in['lng'])) { $lat = (float)$in['lat']; $lng = (float)$in['lng']; }
    elseif ($t['addr'] !== $old['addr']) { $g = $t['addr'] !== '' ? geocode($t['addr']) : null; $lat = $g[0] ?? null; $lng = $g[1] ?? null; }
    $intro = array_key_exists('introducer_id', $in) ? ((int)$in['introducer_id'] ?: null) : $old['introducer_id'];
    if ($intro === $id) { $intro = null; }
    $set = implode(',', array_map(fn($c) => "$c=?", KCM_PERSON_COLS));
    db()->prepare("UPDATE people SET $set, introducer_id=?, lat=?, lng=?, k_name=?, k_phone=?, k_mobile=?, k_addr=?, updated=? WHERE id=?")
        ->execute(array_merge(array_values($t), [$intro, $lat, $lng, $k['k_name'], $k['k_phone'], $k['k_mobile'], $k['k_addr'], now(), $id]));
    oplog($uid, 'person.update', 'people', $id);
}
/** 紹介者の欄：名簿の番号か、氏名（名簿に1人だけのとき） */
function resolve_person(string $s): ?int
{
    $s = trim($s); if ($s === '') { return null; }
    if (ctype_digit($s)) { return person((int)$s) ? (int)$s : null; }
    $q = db()->prepare('SELECT id FROM people WHERE deleted IS NULL AND k_name=? LIMIT 2'); $q->execute([name_key($s)]);
    $r = $q->fetchAll(PDO::FETCH_COLUMN);
    return count($r) === 1 ? (int)$r[0] : null;
}
/** 名簿の絞り込み。一覧も地図も訪問計画もこれを通る */
function people_where(array $f): array
{
    $w = ['p.deleted IS NULL']; $a = [];
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%'; $d = digits($q);
        $w[] = "(p.name LIKE ? ESCAPE '\\' OR p.kana LIKE ? ESCAPE '\\' OR p.addr LIKE ? ESCAPE '\\' OR p.k_name LIKE ? ESCAPE '\\'" . (strlen($d) >= 4 ? ' OR p.k_phone LIKE ? OR p.k_mobile LIKE ?' : '') . ')';
        array_push($a, $like, '%' . mb_convert_kana($q, 'HVc', 'UTF-8') . '%', $like, '%' . name_key($q) . '%');
        if (strlen($d) >= 4) { array_push($a, "%$d%", "%$d%"); }
    }
    foreach (['area', 'kind', 'rank'] as $c) { if (($f[$c] ?? '') !== '') { if ($f[$c] === '-') { $w[] = "p.$c=''"; } else { $w[] = "p.$c=?"; $a[] = (string)$f[$c]; } } }
    if ((int)($f['no_visit'] ?? 0) > 0) { $w[] = '(p.last_visit IS NULL OR p.last_visit < ?)'; $a[] = date('Y-m-d', time() - (int)$f['no_visit'] * 86400); }
    if (!empty($f['next_due'])) { $w[] = 'p.next_visit IS NOT NULL AND p.next_visit <= ?'; $a[] = (string)$f['next_due']; }
    if (!empty($f['has_pos'])) { $w[] = 'p.lat IS NOT NULL'; }
    if (!empty($f['no_pos'])) { $w[] = "p.lat IS NULL AND p.addr<>''"; }
    if ((int)($f['introducer_id'] ?? 0)) { $w[] = 'p.introducer_id=?'; $a[] = (int)$f['introducer_id']; }
    return [implode(' AND ', $w), $a];
}
function people_query(array $f, int $page, int $per = 0): array
{
    $per = $per ?: (int)cfg('page_size'); [$w, $a] = people_where($f);
    $c = db()->prepare("SELECT COUNT(*) FROM people p WHERE $w"); $c->execute($a); $total = (int)$c->fetchColumn();
    $sorts = ['kana' => "p.kana='' , p.kana, p.id", 'area' => 'p.area, p.kana', 'visit' => 'p.last_visit IS NOT NULL, p.last_visit, p.kana', 'new' => 'p.id DESC', 'rank' => "p.rank='' , p.rank, p.kana"];
    $o = $sorts[$f['sort'] ?? 'kana'] ?? $sorts['kana'];
    $pages = max(1, (int)ceil($total / $per)); $page = max(1, min($page, $pages));
    $s = db()->prepare("SELECT p.* FROM people p WHERE $w ORDER BY $o LIMIT $per OFFSET " . (($page - 1) * $per)); $s->execute($a);
    return ['rows' => $s->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}
function household(array $p): array
{
    if ($p['k_addr'] === '') { return []; }
    $s = db()->prepare('SELECT id,name,kind,rank FROM people WHERE deleted IS NULL AND k_addr=? AND id<>? ORDER BY kana LIMIT 20'); $s->execute([$p['k_addr'], $p['id']]);
    return $s->fetchAll();
}

/* ================= 住所から位置（国土地理院の住所検索。住所の文字だけを送る） ================= */

function geocode(string $addr): ?array
{
    if (!cfg('geocode') || trim($addr) === '') { return null; }
    $ctx = stream_context_create(['http' => ['timeout' => 4, 'header' => "User-Agent: kchinjomeibo/" . KCM_VERSION . "\r\n"]]);
    $j = @file_get_contents('https://msearch.gsi.go.jp/address-search/AddressSearch?q=' . rawurlencode($addr), false, $ctx);
    $d = $j ? json_decode($j, true) : null;
    $c = $d[0]['geometry']['coordinates'] ?? null;
    if (!is_array($c) || !is_numeric($c[0] ?? null) || !is_numeric($c[1] ?? null)) { return null; }
    $lat = (float)$c[1]; $lng = (float)$c[0];
    return ($lat > 20 && $lat < 46 && $lng > 122 && $lng < 154) ? [$lat, $lng] : null;
}
/** 位置の無い人に、まとめて位置を付ける（1回に $n 人まで。相手のサーバーに続けて送りすぎない） */
function geocode_missing(int $n = 20): array
{
    $rows = db()->query("SELECT id, addr FROM people WHERE deleted IS NULL AND lat IS NULL AND addr<>'' ORDER BY id LIMIT " . (int)$n)->fetchAll();
    $ok = 0;
    foreach ($rows as $r) {
        $g = geocode($r['addr']);
        if ($g) { db()->prepare('UPDATE people SET lat=?, lng=? WHERE id=?')->execute([$g[0], $g[1], $r['id']]); $ok++; }
        usleep(200000);
    }
    return ['tried' => count($rows), 'ok' => $ok];
}
function dist_km(float $la1, float $lo1, float $la2, float $lo2): float
{
    $r = 6371.0; $dl = deg2rad($la2 - $la1); $dg = deg2rad($lo2 - $lo1);
    $x = sin($dl / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dg / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($x)));
}
/** 近い順に回る順番（出発点から、いちばん近い人へ、を繰り返す。道のりではなく直線） */
function route_order(array $rows, ?array $start = null): array
{
    $rest = array_values(array_filter($rows, fn($r) => $r['lat'] !== null && $r['lng'] !== null));
    if (!$rest) { return []; }
    if (!$start) { $start = [array_sum(array_column($rest, 'lat')) / count($rest), array_sum(array_column($rest, 'lng')) / count($rest)]; }
    $cur = $start; $out = [];
    while ($rest) {
        $bi = 0; $bd = INF;
        foreach ($rest as $i => $r) { $d = dist_km((float)$cur[0], (float)$cur[1], (float)$r['lat'], (float)$r['lng']); if ($d < $bd) { $bd = $d; $bi = $i; } }
        $r = $rest[$bi]; $r['leg_km'] = $bd; $out[] = $r; $cur = [(float)$r['lat'], (float)$r['lng']]; array_splice($rest, $bi, 1);
    }
    return $out;
}
function office_pos(): ?array { $p = setting('office_pos'); return is_array($p) && is_numeric($p[0] ?? null) && is_numeric($p[1] ?? null) ? [(float)$p[0], (float)$p[1]] : null; }

/* ================= 訪問 ================= */

function record_visit(int $personId, string $result, string $memo, ?string $nextOn, int $uid, ?string $at = null, int $planId = 0): int
{
    $p = person($personId); if (!$p) { throw new RuntimeException('その人は名簿にありません'); }
    if (!isset(KCM_VISIT_RESULTS[$result])) { throw new InvalidArgumentException('訪問の結果を選んでください'); }
    $at = $at ?: date('Y-m-d H:i'); $day = substr($at, 0, 10);
    db()->prepare('INSERT INTO visits(person_id,user_id,plan_id,at,result,memo,next_on,created) VALUES(?,?,?,?,?,?,?,?)')->execute([$personId, $uid, $planId ?: null, $at, $result, trim($memo), $nextOn, now()]);
    $vid = (int)db()->lastInsertId();
    db()->prepare('UPDATE people SET last_visit=MAX(COALESCE(last_visit,\'\'),?), last_met=CASE WHEN ? THEN MAX(COALESCE(last_met,\'\'),?) ELSE last_met END, last_result=?, visit_count=visit_count+1, next_visit=?, updated=? WHERE id=?')
        ->execute([$day, in_array($result, ['met', 'family'], true) ? 1 : 0, $day, KCM_VISIT_RESULTS[$result], $nextOn, now(), $personId]);
    if ($planId) { db()->prepare('UPDATE plan_items SET done=? WHERE plan_id=? AND person_id=?')->execute([now(), $planId, $personId]); }
    oplog($uid, 'visit.add', 'people', $personId, $result);
    return $vid;
}
function visits_of(int $personId): array { $s = db()->prepare('SELECT * FROM visits WHERE person_id=? ORDER BY at DESC, id DESC'); $s->execute([$personId]); return $s->fetchAll(); }
function plan(int $id): ?array { $s = db()->prepare('SELECT * FROM plans WHERE id=? AND deleted IS NULL'); $s->execute([$id]); return $s->fetch() ?: null; }
function plans_list(bool $upcomingOnly = false): array
{
    return db()->query('SELECT pl.*, (SELECT COUNT(*) FROM plan_items i WHERE i.plan_id=pl.id) n, (SELECT COUNT(*) FROM plan_items i WHERE i.plan_id=pl.id AND i.done IS NOT NULL) done_n FROM plans pl WHERE pl.deleted IS NULL'
        . ($upcomingOnly ? " AND (pl.on_date IS NULL OR pl.on_date >= '" . today() . "')" : '') . ' ORDER BY pl.on_date IS NULL, pl.on_date DESC, pl.id DESC LIMIT 100')->fetchAll();
}
function plan_items(int $planId): array
{
    $s = db()->prepare('SELECT p.*, i.seq, i.done FROM plan_items i JOIN people p ON p.id=i.person_id WHERE i.plan_id=? AND p.deleted IS NULL ORDER BY i.seq'); $s->execute([$planId]);
    return $s->fetchAll();
}
/** $ids は回る順 */
function create_plan(string $name, ?string $onDate, ?int $userId, array $ids, int $uid): int
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) { throw new InvalidArgumentException('訪問する人を1人以上選んでください'); }
    if (count($ids) > KCM_PLAN_MAX) { throw new InvalidArgumentException('1つの計画は' . KCM_PLAN_MAX . '人までです。地区や条件で分けてください'); }
    $p = db(); $p->beginTransaction();
    $p->prepare('INSERT INTO plans(name,on_date,user_id,created_by,created) VALUES(?,?,?,?,?)')->execute([trim($name) ?: ($onDate ?: today()) . ' の訪問', $onDate, $userId, $uid, now()]);
    $pid = (int)$p->lastInsertId(); $ins = $p->prepare('INSERT INTO plan_items(plan_id,person_id,seq) VALUES(?,?,?)');
    foreach ($ids as $i => $id) { if (person($id)) { $ins->execute([$pid, $id, $i + 1]); } }
    $p->commit(); oplog($uid, 'plan.add', 'plans', $pid, count($ids));
    return $pid;
}

/* ================= 陳情・相談 ================= */

function kase(int $id): ?array { $s = db()->prepare('SELECT * FROM cases WHERE id=? AND deleted IS NULL'); $s->execute([$id]); return $s->fetch() ?: null; }
function case_clean(array $in): array
{
    $st = (string)($in['stage'] ?? ''); if (!in_array($st, stages(), true)) { $st = stages()[0]; }
    return ['title' => trim((string)($in['title'] ?? '')), 'body' => trim((string)($in['body'] ?? '')), 'person_id' => (int)($in['person_id'] ?? 0) ?: null, 'requester' => trim((string)($in['requester'] ?? '')),
            'category' => trim((string)($in['category'] ?? '')), 'channel' => trim((string)($in['channel'] ?? '')), 'stage' => $st, 'owner_id' => (int)($in['owner_id'] ?? 0) ?: null,
            'received_on' => date_or_null($in['received_on'] ?? '') ?? today(), 'due' => date_or_null($in['due'] ?? '')];
}
function create_case(array $in, int $uid): int
{
    $c = case_clean($in);
    if ($c['title'] === '') { throw new InvalidArgumentException('件名を入れてください'); }
    if ($c['person_id'] && !person($c['person_id'])) { $c['person_id'] = null; }
    $c['owner_id'] = $c['owner_id'] ?: $uid;
    $closed = in_array($c['stage'], closed_stages(), true) ? now() : null;
    db()->prepare('INSERT INTO cases(title,body,person_id,requester,category,channel,stage,owner_id,received_on,due,created,updated,closed) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge(array_values($c), [now(), now(), $closed]));
    $id = (int)db()->lastInsertId(); oplog($uid, 'case.add', 'cases', $id);
    return $id;
}
function update_case(int $id, array $in, int $uid): void
{
    $old = kase($id); if (!$old) { throw new RuntimeException('その陳情・相談はありません'); }
    $c = case_clean($in + ['stage' => $old['stage']]);
    if ($c['title'] === '') { throw new InvalidArgumentException('件名を入れてください'); }
    $c['stage'] = $old['stage'];
    db()->prepare('UPDATE cases SET title=?,body=?,person_id=?,requester=?,category=?,channel=?,stage=?,owner_id=?,received_on=?,due=?,updated=? WHERE id=?')->execute(array_merge(array_values($c), [now(), $id]));
    oplog($uid, 'case.update', 'cases', $id);
}
function set_case_stage(int $id, string $stage, int $uid): void
{
    $old = kase($id); if (!$old) { throw new RuntimeException('その陳情・相談はありません'); }
    if (!in_array($stage, stages(), true)) { throw new InvalidArgumentException('その段階はありません'); }
    if ($old['stage'] === $stage) { return; }
    db()->prepare('UPDATE cases SET stage=?, closed=?, updated=? WHERE id=?')->execute([$stage, in_array($stage, closed_stages(), true) ? now() : null, now(), $id]);
    add_case_log($id, 'memo', '段階を「' . $old['stage'] . '」から「' . $stage . '」へ', $uid);
    oplog($uid, 'case.stage', 'cases', $id, $stage);
}
function add_case_log(int $caseId, string $kind, string $body, int $uid, ?string $at = null): int
{
    $body = trim($body); if ($body === '') { throw new InvalidArgumentException('内容を入れてください'); }
    if (!isset(KCM_LOG_KINDS[$kind])) { $kind = 'memo'; }
    db()->prepare('INSERT INTO case_logs(case_id,user_id,at,kind,body,created) VALUES(?,?,?,?,?,?)')->execute([$caseId, $uid, $at ?: date('Y-m-d H:i'), $kind, $body, now()]);
    db()->prepare('UPDATE cases SET updated=? WHERE id=?')->execute([now(), $caseId]);
    return (int)db()->lastInsertId();
}
function case_logs(int $caseId): array { $s = db()->prepare('SELECT * FROM case_logs WHERE case_id=? ORDER BY at DESC, id DESC'); $s->execute([$caseId]); return $s->fetchAll(); }
function cases_query(array $f, int $page = 1, int $per = 0): array
{
    $per = $per ?: (int)cfg('page_size'); $w = ['c.deleted IS NULL']; $a = [];
    if (($f['state'] ?? 'open') === 'open') { $w[] = 'c.closed IS NULL'; } elseif (($f['state'] ?? '') === 'closed') { $w[] = 'c.closed IS NOT NULL'; }
    foreach (['stage', 'category'] as $k) { if (($f[$k] ?? '') !== '') { $w[] = "c.$k=?"; $a[] = (string)$f[$k]; } }
    foreach (['owner_id', 'person_id'] as $k) { if ((int)($f[$k] ?? 0)) { $w[] = "c.$k=?"; $a[] = (int)$f[$k]; } }
    if (!empty($f['overdue'])) { $w[] = "c.closed IS NULL AND c.due IS NOT NULL AND c.due < '" . today() . "'"; }
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') { $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%'; $w[] = "(c.title LIKE ? ESCAPE '\\' OR c.body LIKE ? ESCAPE '\\' OR c.requester LIKE ? ESCAPE '\\' OR p.name LIKE ? ESCAPE '\\')"; array_push($a, $like, $like, $like, $like); }
    $w = implode(' AND ', $w); $from = 'FROM cases c LEFT JOIN people p ON p.id=c.person_id AND p.deleted IS NULL';
    $c = db()->prepare("SELECT COUNT(*) $from WHERE $w"); $c->execute($a); $total = (int)$c->fetchColumn();
    $pages = max(1, (int)ceil($total / $per)); $page = max(1, min($page, $pages));
    $s = db()->prepare("SELECT c.*, p.name person_name, p.area person_area $from WHERE $w ORDER BY c.closed IS NOT NULL, c.due IS NULL, c.due, c.id DESC LIMIT $per OFFSET " . (($page - 1) * $per)); $s->execute($a);
    return ['rows' => $s->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
}
function case_who(array $c): string { return (string)(($c['person_name'] ?? '') ?: $c['requester']); }

/* ================= 行事 ================= */

function event(int $id): ?array { $s = db()->prepare('SELECT * FROM events WHERE id=? AND deleted IS NULL'); $s->execute([$id]); return $s->fetch() ?: null; }
function events_list(): array
{
    return db()->query("SELECT e.*, (SELECT COUNT(*) FROM invites i JOIN people p ON p.id=i.person_id AND p.deleted IS NULL WHERE i.event_id=e.id) n,
        (SELECT COUNT(*) FROM invites i JOIN people p ON p.id=i.person_id AND p.deleted IS NULL WHERE i.event_id=e.id AND i.status IN ('yes','came')) yes_n FROM events e WHERE e.deleted IS NULL ORDER BY e.on_date IS NULL, e.on_date DESC, e.id DESC LIMIT 200")->fetchAll();
}
function create_event(string $name, ?string $onDate, string $place, string $note, int $uid): int
{
    if (trim($name) === '') { throw new InvalidArgumentException('行事の名前を入れてください'); }
    db()->prepare('INSERT INTO events(name,on_date,place,note,created_by,created) VALUES(?,?,?,?,?,?)')->execute([trim($name), $onDate, trim($place), trim($note), $uid, now()]);
    $id = (int)db()->lastInsertId(); oplog($uid, 'event.add', 'events', $id); return $id;
}
/** 絞り込みに当たる人をまとめて案内の対象にする（すでに入っている人はそのまま） */
function invite_by_filter(int $eventId, array $f, int $uid): int
{
    [$w, $a] = people_where($f);
    $s = db()->prepare("INSERT OR IGNORE INTO invites(event_id,person_id,status,updated) SELECT ?, p.id, 'none', ? FROM people p WHERE $w");
    $s->execute(array_merge([$eventId, now()], $a)); $n = $s->rowCount();
    oplog($uid, 'event.invite', 'events', $eventId, ['n' => $n, 'filter' => $f]);
    return $n;
}
function invites_of(int $eventId, string $status = ''): array
{
    $s = db()->prepare('SELECT p.*, i.status, i.note inote FROM invites i JOIN people p ON p.id=i.person_id AND p.deleted IS NULL WHERE i.event_id=?' . ($status !== '' ? ' AND i.status=?' : '') . ' ORDER BY p.area, p.kana, p.id');
    $s->execute($status !== '' ? [$eventId, $status] : [$eventId]); return $s->fetchAll();
}
function set_invite(int $eventId, int $personId, string $status, int $uid): void
{
    if (!isset(KCM_INVITE[$status])) { throw new InvalidArgumentException('その出欠はありません'); }
    db()->prepare('UPDATE invites SET status=?, updated=? WHERE event_id=? AND person_id=?')->execute([$status, now(), $eventId, $personId]);
}

/* ================= CSV ================= */

const KCM_HEADERS = [
    'name'   => ['氏名', '名前', 'お名前', '姓名', 'Name', 'name'],
    'kana'   => ['ふりがな', 'フリガナ', 'カナ', 'かな', 'よみ', '読み', 'kana'],
    'zip'    => ['郵便番号', '〒', 'zip', 'postalCode'],
    'addr'   => ['住所', '住所1', '現住所', 'Address', 'address'],
    'phone'  => ['電話番号', '電話', '自宅電話', 'TEL', 'Tel', 'tel', 'phone'],
    'mobile' => ['携帯', '携帯電話', '携帯番号', 'Mobile', 'mobile'],
    'email'  => ['メール', 'メールアドレス', 'Email', 'email', 'E-mail'],
    'area'   => ['地区', '町内', '町内会', '支部', '学区', 'area'],
    'kind'   => ['区分', '種別', 'kind'],
    'rank'   => ['支持度', 'ランク', 'rank'],
    'introducer' => ['紹介者', '紹介', 'introducer'],
    'note'   => ['備考', 'メモ', 'note', 'notes'],
];
/** CSV の文字コードをそろえて行に分ける（UTF-8 / Shift_JIS、BOM あり／なし） */
function csv_rows(string $raw): array
{
    if (str_starts_with($raw, "\xEF\xBB\xBF")) { $raw = substr($raw, 3); }
    if (!mb_check_encoding($raw, 'UTF-8')) { $raw = mb_convert_encoding($raw, 'UTF-8', 'SJIS-win'); }
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $rows = []; $h = fopen('php://temp', 'r+'); fwrite($h, $raw); rewind($h);
    while (($r = fgetcsv($h, 0, ',', '"', '\\')) !== false) { if (count($r) === 1 && trim((string)$r[0]) === '') { continue; } $rows[] = $r; }
    fclose($h); return $rows;
}
function csv_map_header(array $head): array
{
    $map = [];
    foreach ($head as $i => $name) {
        $n = trim(nfkc((string)$name));
        foreach (KCM_HEADERS as $col => $names) { if (in_array($n, $names, true) || in_array(mb_strtolower($n), array_map('mb_strtolower', $names), true)) { if (!isset($map[$col])) { $map[$col] = $i; } break; } }
    }
    return $map;
}
/** 名簿の取込。$dryRun なら数えるだけ。位置は取込では付けない（あとで「位置を付ける」） */
function import_people(string $raw, int $uid, bool $skipDup = true, bool $dryRun = false): array
{
    $rows = csv_rows($raw);
    if (count($rows) < 2) { throw new InvalidArgumentException('見出しの行と、1行以上のデータが必要です'); }
    $map = csv_map_header($rows[0]);
    if (!isset($map['name'])) { throw new InvalidArgumentException('見出しに「氏名」の列が見つかりません（見出し：' . implode('、', array_slice($rows[0], 0, 8)) . '）'); }
    $added = $dup = $empty = 0; $dups = []; $intro = []; $p = db();
    if (!$dryRun) { $p->beginTransaction(); }
    try {
        foreach (array_slice($rows, 1) as $i => $r) {
            $in = []; foreach ($map as $col => $idx) { $in[$col] = trim((string)($r[$idx] ?? '')); }
            if (($in['name'] ?? '') === '') { $empty++; continue; }
            $d = $skipDup ? find_duplicates(keys_of($in), 1) : [];
            if ($d) { $dup++; if (count($dups) < 50) { $dups[] = ['row' => $i + 2, 'name' => $in['name'], 'with' => $d[0]]; } continue; }
            if (!$dryRun) { $id = add_person($in, $uid, false); if (($in['introducer'] ?? '') !== '') { $intro[$id] = $in['introducer']; } }
            $added++;
        }
        if (!$dryRun) {
            foreach ($intro as $id => $nm) { $x = resolve_person($nm); if ($x && $x !== $id) { $p->prepare('UPDATE people SET introducer_id=? WHERE id=?')->execute([$x, $id]); } }
            $p->commit(); oplog($uid, 'import.csv', 'people', null, ['added' => $added, 'dup' => $dup, 'empty' => $empty]);
        }
    } catch (Throwable $e) { if (!$dryRun && $p->inTransaction()) { $p->rollBack(); } throw $e; }
    return ['added' => $added, 'dup' => $dup, 'empty' => $empty, 'dups' => $dups, 'columns' => array_keys($map), 'rows' => count($rows) - 1];
}
/** 書き出し（UTF-8・BOM つき・Excel で開ける）。先頭が = + - @ のセルは式として動かないよう ' を付ける */
function csv_out(string $name, array $head, iterable $rows): never
{
    header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="' . $name . '"');
    echo "\xEF\xBB\xBF"; $o = fopen('php://output', 'w'); fputcsv($o, $head, ',', '"', '\\');
    foreach ($rows as $r) { fputcsv($o, array_map(fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false && !preg_match('/^[+\-]?[\d\-() ]+$/', $v) ? "'" . $v : $v, $r), ',', '"', '\\'); }
    exit;
}

/* ================= AI（設定した先にだけ送る。OpenAI 互換） ================= */

function ai_on(): bool { return (string)cfg('ai_url') !== ''; }
function ai_chat(string $system, string $user, int $maxTokens = 700): string
{
    if (!ai_on()) { throw new RuntimeException('AI の接続先が設定されていません'); }
    $body = json_encode(['model' => (string)cfg('ai_model'), 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], 'max_tokens' => $maxTokens, 'temperature' => 0.3, 'stream' => false, 'think' => false, 'reasoning_effort' => 'none'], JSON_UNESCAPED_UNICODE);
    $hd = "Content-Type: application/json\r\n" . ((string)cfg('ai_token') !== '' ? 'Authorization: Bearer ' . cfg('ai_token') . "\r\n" : '');
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => $hd, 'content' => $body, 'timeout' => 90, 'ignore_errors' => true]]);
    $j = @file_get_contents((string)cfg('ai_url'), false, $ctx);
    $d = $j ? json_decode($j, true) : null;
    $t = trim((string)($d['choices'][0]['message']['content'] ?? ''));
    if ($t === '') { throw new RuntimeException('AI から答えが返りませんでした。しばらくおいてお試しください'); }
    return $t;
}
function case_text(array $c): string
{
    $o = "件名：{$c['title']}\n分野：{$c['category']}\n受付日：{$c['received_on']}\n内容：\n{$c['body']}\n";
    $logs = array_reverse(array_filter(case_logs((int)$c['id']), fn($l) => $l['kind'] !== 'ai'));
    if ($logs) { $o .= "\nこれまでの経過：\n"; foreach ($logs as $l) { $o .= '- ' . $l['at'] . '（' . (KCM_LOG_KINDS[$l['kind']] ?? '') . '）' . $l['body'] . "\n"; } }
    return mb_substr($o, 0, 6000);
}
function ai_case_summary(array $c): string
{
    return ai_chat('あなたは議員事務所の秘書を手伝う係です。渡された陳情・相談の記録だけをもとに、日本語で短く整理します。記録に無いことは書きません。個人名は書きません。',
        "次の陳情・相談を、①困りごと（1〜2文）②これまでにしたこと ③次にやること、の3つに分けて箇条書きでまとめてください。全部で200字ほど。\n\n" . case_text($c));
}
function ai_case_reply(array $c): string
{
    return ai_chat('あなたは議員事務所の秘書を手伝う係です。渡された陳情・相談の記録だけをもとに、相談した方へ送る経過のお知らせの下書きを書きます。記録に無い事実・約束・日付は書きません。分からないところは「（確認中）」と書きます。ていねいで短い文にします。',
        "次の記録をもとに、相談した方へ送る文面の下書きを300字ほどで書いてください。宛名は「○○様」、差出人は「（事務所名）」のままにしてください。\n\n" . case_text($c), 900);
}

/* ================= 毎日の仕事（cron なし。その日の最初の操作で） ================= */

function daily_backup(): void
{
    $day = date('Ymd');
    if (meta('last_backup') === $day) { return; }
    meta('last_backup', $day);
    $dir = dirname((string)cfg('db')) . '/backup';
    if (!is_dir($dir)) { mkdir($dir, 0700, true); }
    $f = $dir . '/kchinjomeibo-' . $day . '.sqlite';
    if (!is_file($f)) { try { db()->exec('VACUUM INTO ' . db()->quote($f)); @chmod($f, 0600); } catch (Throwable) {} }
    foreach (glob($dir . '/kchinjomeibo-*.sqlite') ?: [] as $old) { if (filemtime($old) < time() - (int)cfg('backup_days') * 86400) { @unlink($old); } }
}

/* ================= デモ（氏名・住所・相談の内容はすべて架空） ================= */

function demo_reset_if_old(PDO $p): void
{
    $s = $p->query("SELECT v FROM meta WHERE k='demo_seeded'")->fetchColumn();
    if ($s && $s > date('Y-m-d H:i:s', time() - 86400)) { return; }
    foreach (['oplog', 'invites', 'events', 'case_logs', 'cases', 'plan_items', 'plans', 'visits', 'people', 'users', 'settings'] as $t) { $p->exec("DELETE FROM $t"); }
    $p->exec("DELETE FROM meta WHERE k LIKE 'ai_%'");
    $p->exec('DELETE FROM sqlite_sequence');
    seed_demo($p);
}
function seed_demo(PDO $p): void
{
    $p->prepare('INSERT OR REPLACE INTO meta(k,v) VALUES(?,?)')->execute(['demo_seeded', now()]);
    $ph = password_hash('demo-' . bin2hex(random_bytes(4)), PASSWORD_DEFAULT);
    foreach ([['hisho1@demo.example', '秘書・佐藤', 'member'], ['hisho2@demo.example', '秘書・鈴木', 'member'], ['admin@demo.example', '事務所長（管理者）', 'admin']] as [$e, $n, $r]) {
        $p->prepare('INSERT INTO users(email,name,pass_hash,role,created) VALUES(?,?,?,?,?)')->execute([$e, $n, $ph, $r, now()]);
    }
    $ins = fn(string $k, $v) => $p->prepare('INSERT OR REPLACE INTO settings(k,v,updated) VALUES(?,?,?)')->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE), now()]);
    $ins('office', 'くらげ太郎事務所（デモ）'); $ins('office_pos', [35.3039, 136.8031]);
    // 町の名前と位置は実在（愛知県一宮市）。番地・氏名・電話は機械的に作った架空のもの
    $towns = [['本町', '491-0859', 35.3040, 136.8010], ['真清田', '491-0043', 35.3075, 136.8022], ['大和町馬引', '491-0922', 35.2890, 136.7800], ['今伊勢町本神戸', '491-0053', 35.3230, 136.7900],
              ['木曽川町黒田', '493-0001', 35.3440, 136.7780], ['起', '494-0006', 35.3050, 136.7430], ['丹陽町九日市場', '491-0824', 35.2780, 136.8350], ['浅井町尾関', '491-0101', 35.3400, 136.8300]];
    $areaOf = ['中央', '中央', '大和', '今伊勢', '木曽川', '尾西', '丹陽', '浅井'];
    $surn = [['田中', 'たなか'], ['鈴木', 'すずき'], ['伊藤', 'いとう'], ['山本', 'やまもと'], ['加藤', 'かとう'], ['渡辺', 'わたなべ'], ['中村', 'なかむら'], ['小林', 'こばやし'], ['松本', 'まつもと'], ['林', 'はやし'],
             ['森', 'もり'], ['山田', 'やまだ'], ['斎藤', 'さいとう'], ['清水', 'しみず'], ['近藤', 'こんどう'], ['水野', 'みずの'], ['服部', 'はっとり'], ['河合', 'かわい'], ['神谷', 'かみや'], ['杉浦', 'すぎうら']];
    $given = [['一郎', 'いちろう'], ['花子', 'はなこ'], ['健二', 'けんじ'], ['美咲', 'みさき'], ['正夫', 'まさお'], ['和子', 'かずこ'], ['大輔', 'だいすけ'], ['恵', 'めぐみ'], ['茂', 'しげる'], ['洋子', 'ようこ'], ['翔太', 'しょうた'], ['久美子', 'くみこ'], ['勝', 'まさる']];
    $kinds = KCM_DEFAULT_KINDS; $memos = ['町内会の役員をしている', '', '', '商店街で店を営む', '', '消防団', '', '子ども会の世話役', '', '', '老人クラブの会長', ''];
    mt_srand(20261110);
    $n = 240; $ids = [];
    for ($i = 0; $i < $n; $i++) {
        $pair = $i % 6 === 1;                                    // 6人に1人は、前の人の家族（同じ住所・同じ固定電話）
        $ti = $pair ? $pti : ($i * 5 + intdiv($i, 8)) % 8; $pti = $ti; $tw = $towns[$ti];
        $sn = $pair ? $psn : $surn[($i * 7 + intdiv($i, 20)) % 20]; $psn = $sn; $gv = $given[($i * 3 + intdiv($i, 13)) % 13];
        if (!$pair) {
            $ban = (1 + $i % 4) . '丁目' . (1 + ($i * 3) % 28) . '番' . (1 + ($i * 7) % 19) . '号';
            $tel = sprintf('0586-%02d-%04d', 20 + $i % 70, 1000 + ($i * 37) % 9000);
            $lat = $tw[2] + (mt_rand(-600, 600) / 100000); $lng = $tw[3] + (mt_rand(-700, 700) / 100000);
        }
        $t = ['name' => $sn[0] . ' ' . $gv[0], 'kana' => $sn[1] . ' ' . $gv[1], 'zip' => $tw[1], 'addr' => '愛知県一宮市' . $tw[0] . $ban, 'phone' => $tel,
              'mobile' => $i % 3 === 0 ? sprintf('090-%04d-%04d', 1000 + ($i * 53) % 9000, 1000 + ($i * 91) % 9000) : '', 'email' => '', 'area' => $areaOf[$ti],
              'kind' => $kinds[[0, 0, 1, 1, 1, 0, 2, 1, 3, 4, 1, 5][$i % 12]], 'rank' => ['A', 'B', 'B', 'C', 'A', 'B', '', 'C'][$i % 8], 'note' => $memos[$i % 12], 'lat' => $lat, 'lng' => $lng,
              'introducer_id' => ($i > 12 && $i % 5 === 0) ? $ids[($i * 3) % 12] : 0];
        $ids[] = add_person($t, 3, false);
    }
    // 訪問の記録（6割の人に1〜3回）
    $vm = ['お元気そうだった。通学路の横断歩道の件を気にしていた', '', '留守。会報を郵便受けに', 'ご主人が応対。奥様は入院中とのこと', '', '孫の保育園が決まらないと相談あり→陳情に登録', '次の集会に来てくださるとのこと', '', '引っ越したと隣の方から聞いた', '道路の水たまりの件、写真を見せてもらった'];
    $rs = ['met', 'absent', 'left', 'family', 'met', 'met', 'met', 'absent', 'moved', 'met'];
    foreach ($ids as $i => $id) {
        if ($i % 5 >= 3) { continue; }
        for ($k = 0, $c = 1 + $i % 3; $k < $c; $k++) {
            $days = 3 + ($i * 7 + $k * 61) % 200; $r = $rs[($i + $k) % 10];
            $next = ($k === 0 && $i % 9 === 0) ? date('Y-m-d', strtotime(($i % 2 ? '+' : '-') . (1 + $i % 6) . ' days')) : null;
            record_visit($id, $r, $vm[($i + $k) % 10], $next, 1 + $i % 2, date('Y-m-d', strtotime("-$days days")) . sprintf(' %02d:%02d', 10 + $i % 7, ($i * 13) % 60));
        }
    }
    // record_visit は最後に入れた記録で next_visit / last_result を上書きするので、いちばん新しい記録に合わせ直す
    $p->exec("UPDATE people SET last_result=COALESCE((SELECT CASE v.result WHEN 'met' THEN '本人と話した' WHEN 'family' THEN '家族と話した' WHEN 'absent' THEN '不在' WHEN 'left' THEN '不在（資料を置いた）' ELSE '転居・不明' END FROM visits v WHERE v.person_id=people.id ORDER BY v.at DESC LIMIT 1),''),
              next_visit=(SELECT MAX(v.next_on) FROM visits v WHERE v.person_id=people.id)");
    // 訪問計画（今日・大和地区でしばらく会えていない人）
    $rows = $p->query("SELECT * FROM people WHERE area='大和' AND (last_visit IS NULL OR last_visit < '" . date('Y-m-d', time() - 90 * 86400) . "') LIMIT 14")->fetchAll();
    $pl = create_plan('大和地区（3か月会えていない方）', today(), 1, array_column(route_order($rows, [35.3039, 136.8031]), 'id'), 3);
    $pi = plan_items($pl); foreach (array_slice($pi, 0, 3) as $j => $r) { record_visit((int)$r['id'], ['met', 'absent', 'met'][$j], ['会報をお渡しした。お元気', '', '側溝のふたの件を聞いた'][$j], null, 1, date('Y-m-d') . ' 10:' . (10 + $j * 15), $pl); }
    $rows = $p->query("SELECT * FROM people WHERE area='木曽川' AND rank='A' LIMIT 10")->fetchAll();
    create_plan('木曽川地区（支持度A）あいさつ回り', date('Y-m-d', strtotime('+3 days')), 2, array_column(route_order($rows, [35.3039, 136.8031]), 'id'), 3);
    // 陳情・相談
    $cs = [
        ['通学路の横断歩道の白線が消えかけている', '○○小学校の北側の交差点。朝は交通量が多く、子どもが渡るのに時間がかかる。白線の引き直しと、できれば押しボタン信号を、という要望。', '道路・交通', '訪問先で', '行政へ照会', -20, 5, [['memo', '現地を確認。白線は半分ほど消えている。写真を撮った', -19], ['ask', '市の道路課に白線の引き直しを照会。警察（公安委員会）の所管との回答で、交通課へ回してもらった', -15], ['tell', 'ご本人に、照会中であることを電話でお伝えした', -14]]],
        ['保育園の入園が決まらない', '4月から職場に戻る予定だが、第3希望まで保留になった。空きの見通しと、ほかに使える制度を知りたい。', '子育て・教育', '来所', '調査中', -9, 3, [['memo', '保育課に空きの状況を確認する。認可外の補助の制度も調べる', -8]]],
        ['側溝のふたが割れている', '自宅前の側溝のふたが割れていて、夜に足を取られそうになった。', '道路・交通', '訪問先で', '回答待ち', -30, -2, [['ask', '市の維持課に連絡。現地を見てから回答するとのこと', -28], ['memo', '維持課から「今月中に交換の予定」と電話。日付は追って連絡とのこと', -12]]],
        ['ごみ集積所のカラス対策', '町内の集積所がカラスに荒らされる。ネットの貸し出しや、ボックス型への補助があるか知りたい。', '環境・ごみ', '電話', '回答済み', -40, null, [['ask', '清掃事業所に制度を確認', -38], ['answer', '防鳥ネットの無償貸与あり。ボックス型は町内会への補助（上限あり）。申請書をもらった', -33], ['tell', '町内会長さんに申請書をお届けした', -31]]],
        ['介護認定の結果に納得がいかない', '母の要介護度が下がり、使えるサービスが減った。見直しを求める方法を知りたい。', '福祉・介護', '来所', '受付', -2, 10, []],
        ['避難所の備蓄を確認したい', '公民館が避難所になっているが、備蓄の量と、発電機があるかを町内で知っておきたい。', '防災・防犯', '行事で', '調査中', -12, 8, [['memo', '危機管理課に備蓄の一覧を依頼', -10]]],
        ['街路灯が切れている', '公園の東側の街路灯が2か月ほど消えたまま。', '防災・防犯', 'メール', '完了', -60, null, [['ask', '市の維持課に連絡', -58], ['answer', '球切れ。翌週に交換済みとの回答', -50], ['tell', 'メールでご報告。お礼の返信あり', -49]]],
        ['商店街の空き店舗の活用', '空き店舗が3軒続いている。出店の補助や、若い人を呼ぶ取り組みの例を知りたい。', '産業・雇用', '来所', '調査中', -15, 14, [['memo', '商工課の補助の一覧をもらう。他市の例を調べる', -13]]],
        ['バス路線の減便', '平日昼の便が減り、通院が不便になった。', '道路・交通', '手紙', '行政へ照会', -25, 1, [['ask', '交通政策課に利用者数と見直しの予定を照会', -22]]],
        ['学童保育の待機', '3年生から学童に入れないと言われた。', '子育て・教育', '電話', '受付', -1, 12, []],
        ['河川敷の草刈り', '堤防の草が伸びて見通しが悪い。年に何回刈るのか知りたい。', '環境・ごみ', '訪問先で', '回答済み', -35, null, [['ask', '県の建設事務所に確認', -33], ['answer', '年2回（6月・10月）。次回は来月上旬の予定との回答', -27], ['tell', '訪問してお伝えした', -26]]],
        ['空き家の管理', '隣の空き家の庭木が道路にはみ出している。', '防災・防犯', '電話', '回答待ち', -18, -4, [['ask', '市の空き家対策の窓口に連絡。所有者に通知を出すとのこと', -16]]],
    ];
    foreach ($cs as $i => [$title, $body, $cat, $ch, $stage, $recv, $due, $logs]) {
        $cid = create_case(['title' => $title, 'body' => $body, 'category' => $cat, 'channel' => $ch, 'stage' => $stage, 'person_id' => $i % 4 === 3 ? 0 : $ids[($i * 17 + 5) % $n], 'requester' => $i % 4 === 3 ? '町内会長（名簿に無い方）' : '',
                            'owner_id' => 1 + $i % 2, 'received_on' => date('Y-m-d', strtotime("$recv days")), 'due' => $due === null ? '' : date('Y-m-d', strtotime(($due >= 0 ? '+' : '') . "$due days"))], 3);
        foreach ($logs as [$k, $b, $d]) { add_case_log($cid, $k, $b, 1 + $i % 2, date('Y-m-d', strtotime("$d days")) . ' 14:00'); }
    }
    // 行事
    $e1 = create_event('新春のつどい（後援会）', date('Y-m-d', strtotime('+25 days')), '市民会館 2階ホール', '会費の案内は別紙。受付は30分前から', 3);
    $p->exec("INSERT INTO invites(event_id,person_id,status,updated) SELECT $e1, id, 'none', '" . now() . "' FROM people WHERE kind='後援会員'");
    $p->exec("UPDATE invites SET status=CASE person_id % 5 WHEN 0 THEN 'yes' WHEN 1 THEN 'sent' WHEN 2 THEN 'yes' WHEN 3 THEN 'no' ELSE 'sent' END WHERE event_id=$e1");
    $e2 = create_event('市政報告会（尾西地区）', date('Y-m-d', strtotime('+10 days')), '尾西生涯学習センター', '', 3);
    $p->exec("INSERT INTO invites(event_id,person_id,status,updated) SELECT $e2, id, CASE id % 3 WHEN 0 THEN 'yes' ELSE 'sent' END, '" . now() . "' FROM people WHERE area='尾西'");
    $p->exec('DELETE FROM oplog');
}
