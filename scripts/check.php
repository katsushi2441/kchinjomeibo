<?php
/** 自己テスト（決まりの部分だけ確かめる）。デプロイの前に必ず通す。  php scripts/check.php */
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/kcm_check_' . getmypid(); @mkdir($tmp);
require __DIR__ . '/../public/kcm_lib.php';
$KCM = array_merge($KCM, ['db' => $tmp . '/t.sqlite', 'demo' => false, 'geocode' => false, 'ai_url' => '', 'public_url' => '']);
$ok = 0; $ng = 0;
function t(string $name, bool $c) { global $ok, $ng; if ($c) { $ok++; } else { $ng++; echo "NG: $name\n"; } }

schema(db());
$admin = create_user('a@x.example', '所長', 'password123', 'admin');
$sato = create_user('s@x.example', '佐藤', 'password123', 'member');
try { create_user('b@x.example', '短い', 'short', 'member'); t('短いパスワードは弾く', false); } catch (InvalidArgumentException) { t('短いパスワードは弾く', true); }
t('氏名：空白と全角のゆれ', name_key('山田　太郎') === name_key('山田 太郎') && name_key('山田太郎') === name_key(' 山田太郎 '));
t('電話は数字だけ', digits('０５８６-１２-３４５６') === '0586123456');
t('住所：丁目番地と全角ハイフン', address_key('愛知県一宮市本町三丁目４番５号') === address_key('一宮市本町3-4-5'));

$a = add_person(['name' => '山田 太郎', 'kana' => 'やまだ たろう', 'addr' => '愛知県一宮市本町3-4-5', 'phone' => '0586-12-3456', 'area' => '中央', 'rank' => 'A'], $admin, false);
$b = add_person(['name' => '山田 花子', 'kana' => 'やまだ はなこ', 'addr' => '一宮市本町三丁目4番5号', 'phone' => '0586-12-3456', 'area' => '中央'], $admin, false);
t('名簿に加える', $a > 0 && $b > 0);
t('家族（同じ住所・電話、別の氏名）は重複にしない', find_duplicates(keys_of(['name' => '山田 次郎', 'addr' => '一宮市本町3-4-5', 'phone' => '0586-12-3456'])) === []);
$d = find_duplicates(keys_of(['name' => '山田　太郎', 'mobile' => '0586123456']));
t('同じ氏名＋同じ電話は重複', count($d) === 1 && (int)$d[0]['id'] === $a);
$d = find_duplicates(keys_of(['name' => '山田太郎', 'addr' => '愛知県一宮市本町３丁目４－５']));
t('同じ氏名＋同じ住所は重複', count($d) === 1 && (int)$d[0]['id'] === $a);
t('同じ氏名だけでは重複にしない', find_duplicates(keys_of(['name' => '山田太郎', 'addr' => '一宮市大和町1-1'])) === []);
t('世帯：同じ住所の人が出る', array_column(household(person($a)), 'id') == [$b]);
try { add_person(['kana' => 'x'], $admin, false); t('氏名が無いと弾く', false); } catch (InvalidArgumentException) { t('氏名が無いと弾く', true); }
update_person($b, ['name' => '山田 花子', 'addr' => '一宮市本町3-4-5', 'introducer_id' => $a] + person($b), $admin);

$csv = "氏名,ふりがな,住所,電話番号,地区,支持度,紹介者\n鈴木 一郎,すずき いちろう,一宮市大和町2-2,0586-22-0001,大和,B,山田 太郎\n山田太郎,,一宮市本町3-4-5,,,,\n,,,,,,\n佐藤 京子,さとう きょうこ,一宮市今伊勢町9-1,090-1111-2222,今伊勢,,\n";
$r = import_people($csv, $admin, true, true);
t('CSV 確認：追加2・重複1・空1', $r['added'] === 2 && $r['dup'] === 1 && $r['empty'] === 1);
t('CSV 確認では増えない', people_query([], 1)['total'] === 2);
$r = import_people(mb_convert_encoding($csv, 'SJIS-win', 'UTF-8'), $admin);
t('Shift_JIS も取り込める', $r['added'] === 2 && people_query([], 1)['total'] === 4);
$suzuki = resolve_person('鈴木 一郎');
t('紹介者がつながる', $suzuki && (int)person($suzuki)['introducer_id'] === $a);
try { import_people("住所,電話\nx,y\n", $admin); t('氏名の列が無い CSV は弾く', false); } catch (InvalidArgumentException) { t('氏名の列が無い CSV は弾く', true); }
t('絞り込み：地区', people_query(['area' => '大和'], 1)['total'] === 1);
t('絞り込み：ことば（電話）', people_query(['q' => '0586-22'], 1)['total'] === 1);
t('絞り込み：支持度が未設定', people_query(['rank' => '-'], 1)['total'] === 2);

// 訪問
try { record_visit($a, 'nothing', '', null, $sato); t('無い結果は弾く', false); } catch (InvalidArgumentException) { t('無い結果は弾く', true); }
record_visit($a, 'absent', '', null, $sato, '2026-10-01 10:00');
record_visit($a, 'met', '道路の話', '2026-12-01', $sato, '2026-11-01 10:00');
$p = person($a);
t('訪問で最後の訪問・回数・次の約束が入る', $p['last_visit'] === '2026-11-01' && $p['last_met'] === '2026-11-01' && (int)$p['visit_count'] === 2 && $p['next_visit'] === '2026-12-01');
t('しばらく訪問していない人で絞れる', people_query(['no_visit' => 30], 1)['total'] >= 3);
// 回る順番（直線で近い順）
db()->exec("UPDATE people SET lat=35.30, lng=136.80 WHERE id=$a"); db()->exec("UPDATE people SET lat=35.40, lng=136.80 WHERE id=$b"); db()->exec("UPDATE people SET lat=35.32, lng=136.80 WHERE id=$suzuki");
$rows = array_map('person', [$b, $suzuki, $a, resolve_person('佐藤 京子')]);
$o = route_order($rows, [35.29, 136.80]);
t('近い順に並ぶ・位置の無い人は外す', array_column($o, 'id') == [$a, $suzuki, $b] && $o[0]['leg_km'] > 1.0 && $o[0]['leg_km'] < 1.3);
$pl = create_plan('試験', today(), $sato, [$a, $suzuki, $b], $admin);
t('訪問計画', count(plan_items($pl)) === 3 && (int)plans_list()[0]['n'] === 3);
record_visit($suzuki, 'left', '', null, $sato, null, $pl);
t('計画の中で記録すると済みになる', (int)plans_list()[0]['done_n'] === 1);

// 陳情・相談
try { create_case(['title' => ''], $sato); t('件名が無いと弾く', false); } catch (InvalidArgumentException) { t('件名が無いと弾く', true); }
$c = create_case(['title' => 'カーブミラー', 'body' => '見通しが悪い', 'person_id' => $a, 'due' => '2020-01-01', 'owner_id' => $sato], $sato);
t('受け付けると最初の段階', kase($c)['stage'] === stages()[0] && kase($c)['closed'] === null);
t('期限を過ぎたもので絞れる', cases_query(['overdue' => 1])['total'] === 1);
try { set_case_stage($c, '存在しない', $sato); t('無い段階は弾く', false); } catch (InvalidArgumentException) { t('無い段階は弾く', true); }
set_case_stage($c, '完了', $sato);
t('完了で終わりになる', kase($c)['closed'] !== null && cases_query([])['total'] === 0 && cases_query(['state' => 'closed'])['total'] === 1);
set_case_stage($c, '調査中', $sato); t('戻すと対応中に戻る', kase($c)['closed'] === null);
add_case_log($c, 'ask', '市の道路課に照会', $sato);
t('経過が残る（段階の変更も）', count(case_logs($c)) >= 3);
t('AI は設定が無ければ使わない', ai_on() === false);

// 行事
$e = create_event('市政報告会', '2026-12-10', '公民館', '', $admin);
$n = invite_by_filter($e, ['area' => '中央'], $admin); $n2 = invite_by_filter($e, [], $admin);
t('条件で案内の対象を加える・二重にしない', $n === 2 && $n2 === 2 && count(invites_of($e)) === 4);
set_invite($e, $a, 'yes', $sato);
t('出欠', count(invites_of($e, 'yes')) === 1 && (int)events_list()[0]['yes_n'] === 1);
try { set_invite($e, $a, 'x', $sato); t('無い出欠は弾く', false); } catch (InvalidArgumentException) { t('無い出欠は弾く', true); }

daily_backup(); t('控えが取れる', count(glob($tmp . '/backup/kchinjomeibo-*.sqlite') ?: []) === 1);

// デモ：架空のデータ（db() は1つの DB を握るので、別のプロセスで確かめる）
$code = 'require ' . var_export(__DIR__ . '/../public/kcm_lib.php', true) . '; $KCM = array_merge($KCM, ["demo" => true, "geocode" => false, "db" => ' . var_export($tmp . '/demo.sqlite', true) . ']); $p = db(); $c = fn($q) => (int)$p->query($q)->fetchColumn();'
    . 'echo json_encode([$c("SELECT COUNT(*) FROM people"), $c("SELECT COUNT(*) FROM cases"), $c("SELECT COUNT(*) FROM events"), $c("SELECT COUNT(*) FROM people WHERE lat IS NULL"), $c("SELECT COUNT(*) FROM cases WHERE closed IS NULL AND due < date(\'now\',\'localtime\')"), $c("SELECT COUNT(*) FROM users"), $c("SELECT COUNT(*) FROM plans")]);';
$d = json_decode((string)shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code)), true) ?: [];
t('デモの種：名簿240・陳情12・行事2・利用者3・計画2', array_slice($d, 0, 3) == [240, 12, 2] && ($d[5] ?? 0) === 3 && ($d[6] ?? 0) === 2);
t('デモの種：全員に位置がある', ($d[3] ?? 1) === 0);
t('デモの種：期限を過ぎた陳情がある', ($d[4] ?? 0) > 0);

foreach (glob($tmp . '/backup/*') ?: [] as $f) { unlink($f); } @rmdir($tmp . '/backup');
foreach (glob($tmp . '/*') ?: [] as $f) { unlink($f); } @rmdir($tmp);
echo "ok=$ok ng=$ng\n"; exit($ng ? 1 : 0);
