<?php
// ============================================================================
// Los Polos Kalózok — Multiplayer relay (PHP 5.6!)
//   Rövid-pollos, fájl-alapú szoba-relay. Nincs perzisztens folyamat/WebSocket.
//   Végpontok (mp.php?a=...):
//     create  POST {name,seed?}                  -> {code,pid,token,seed,settings}
//     join    POST {code,name}                   -> {code,pid,token,seed,settings,phase}
//     poll    POST {code,pid,token,st,chat,...}  -> teljes szoba-pillanatkép (delta chat/event)
//     leave   POST {code,pid,token}              -> {ok}
//   Szoba-tár: ./mp_rooms/<CODE>.json  (flock-kal védve)
//   Model: kliens-authoritatív a SAJÁT hajójára; a HOST szimulálja a botokat
//   (enemies) és broadcastol; PvP-találat esemény-alapú (events[]).
// ============================================================================
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

$DIR = dirname(__FILE__) . '/mp_rooms';
if (!is_dir($DIR)) { @mkdir($DIR, 0755, true); }

// --- konstansok ---
$PLAYER_TTL   = 9;    // mp: ennyi mp inaktivitás után kiesik a játékos
$ROOM_TTL     = 21600;// 6 óra után halott szoba törlése
$CHAT_KEEP    = 80;
$EVENT_KEEP   = 200;
$EVENT_TTL    = 14;
$MAX_PLAYERS_HARD = 8;

function now_ms(){ return round(microtime(true) * 1000); }
function now_s(){ return time(); }

function jout($o){ echo json_encode($o); exit; }
function jerr($m){ echo json_encode(array('ok'=>false, 'err'=>$m)); exit; }

function body_json(){
  $raw = file_get_contents('php://input');
  if ($raw === '' || $raw === false) return array();
  $d = json_decode($raw, true);
  return is_array($d) ? $d : array();
}

function gen_code(){
  // félreérthető karakterek nélkül (0/O, 1/I kihagyva)
  $al = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $c = '';
  for ($i=0;$i<6;$i++){ $c .= $al[mt_rand(0, strlen($al)-1)]; }
  return $c;
}
function gen_id($p){ return $p . '_' . dechex(mt_rand(0x100000,0xffffff)) . dechex(mt_rand(0x100000,0xffffff)); }
function clean_str($s, $max){
  if (!is_string($s)) return '';
  $s = trim($s);
  $s = preg_replace('/[\x00-\x1f\x7f]/u', '', $s);
  if (mb_strlen($s, 'UTF-8') > $max) $s = mb_substr($s, 0, $max, 'UTF-8');
  return $s;
}
function room_path($code){
  global $DIR;
  if (!preg_match('/^[A-Z0-9]{4,8}$/', $code)) return false;
  return $DIR . '/' . $code . '.json';
}

// szoba beolvasás+mutáció+kiírás atomikusan (flock)
function with_room($code, $create, $fn){
  $path = room_path($code);
  if ($path === false) return array('err'=>'bad_code');
  $mode = $create ? 'c+' : 'r+';
  $fh = @fopen($path, $mode);
  if (!$fh){ return array('err'=>'no_room'); }
  if (!flock($fh, LOCK_EX)){ fclose($fh); return array('err'=>'lock'); }
  $raw = stream_get_contents($fh);
  $room = ($raw !== '' && $raw !== false) ? json_decode($raw, true) : null;
  $res = call_user_func($fn, $room);
  // $res = array('room'=>..., 'out'=>..., 'delete'=>bool)
  if (isset($res['delete']) && $res['delete']){
    ftruncate($fh, 0); flock($fh, LOCK_UN); fclose($fh); @unlink($path);
  } else if (isset($res['room']) && $res['room'] !== null){
    $enc = json_encode($res['room']);
    ftruncate($fh, 0); rewind($fh); fwrite($fh, $enc); fflush($fh);
    flock($fh, LOCK_UN); fclose($fh);
  } else {
    flock($fh, LOCK_UN); fclose($fh);
  }
  return isset($res['out']) ? $res['out'] : array('ok'=>true);
}

// halott játékosok kiszórása + host-migráció; visszaadja: törlendő-e a szoba
function prune_room(&$room){
  global $PLAYER_TTL;
  $t = now_s();
  if (!isset($room['players']) || !is_array($room['players'])) $room['players'] = array();
  foreach ($room['players'] as $pid=>$p){
    if (!isset($p['last']) || ($t - $p['last']) > $PLAYER_TTL){
      unset($room['players'][$pid]);
    }
  }
  // host-migráció: ha a host kiesett, a legrégebb csatlakozott kapja
  if (!isset($room['players'][$room['host']])){
    $best = null; $bt = PHP_INT_MAX;
    foreach ($room['players'] as $pid=>$p){
      $j = isset($p['joined']) ? $p['joined'] : $t;
      if ($j < $bt){ $bt = $j; $best = $pid; }
    }
    if ($best !== null){
      $room['host'] = $best;
      // rendszerüzenet a host-váltásról
      push_chat($room, '', (isset($room['players'][$best]['name'])?$room['players'][$best]['name']:'Valaki') . ' lett az új kapitány (host)', true);
    }
  }
  return count($room['players']) === 0;
}

function push_chat(&$room, $name, $msg, $sys){
  global $CHAT_KEEP;
  if (!isset($room['cid'])) $room['cid'] = 0;
  $room['cid']++;
  if (!isset($room['chat'])) $room['chat'] = array();
  $room['chat'][] = array('id'=>$room['cid'], 'n'=>$name, 'm'=>$msg, 't'=>now_s(), 'sys'=>$sys?1:0);
  if (count($room['chat']) > $CHAT_KEEP) $room['chat'] = array_slice($room['chat'], -$CHAT_KEEP);
}
function push_event(&$room, $ev){
  global $EVENT_KEEP;
  if (!isset($room['eid'])) $room['eid'] = 0;
  $room['eid']++;
  $ev['eid'] = $room['eid'];
  $ev['t'] = now_s();
  if (!isset($room['events'])) $room['events'] = array();
  $room['events'][] = $ev;
  if (count($room['events']) > $EVENT_KEEP) $room['events'] = array_slice($room['events'], -$EVENT_KEEP);
}

// kimeneti pillanatkép egy adott játékosnak (delta chat/event)
function snapshot(&$room, $me, $csince, $esince){
  global $EVENT_TTL;
  $t = now_s();
  $players = array();
  foreach ($room['players'] as $pid=>$p){
    $players[] = array(
      'pid'=>$pid,
      'name'=>isset($p['name'])?$p['name']:'?',
      'ready'=>isset($p['ready'])?($p['ready']?1:0):0,
      'color'=>isset($p['color'])?intval($p['color']):0,
      'alive'=>isset($p['alive'])?($p['alive']?1:0):1,
      'st'=>isset($p['st'])?$p['st']:null,
      'ping'=>isset($p['ping'])?intval($p['ping']):0,
      'host'=>($pid===$room['host'])?1:0
    );
  }
  // chat delta
  $chat = array();
  if (isset($room['chat'])) foreach ($room['chat'] as $c){ if ($c['id'] > $csince) $chat[] = $c; }
  // event delta — nekem címzett (to==me | to=='all' | to=='host' ha én vagyok a host)
  $events = array();
  if (isset($room['events'])) foreach ($room['events'] as $ev){
    if ($ev['eid'] <= $esince) continue;
    if (($t - $ev['t']) > $EVENT_TTL) continue;
    $to = isset($ev['to'])?$ev['to']:'all';
    $mineHost = ($me === $room['host']);
    if ($to === 'all' || $to === $me || ($to === 'host' && $mineHost)){
      $events[] = $ev;
    }
  }
  return array(
    'ok'=>true,
    'now'=>now_s(),
    'code'=>$room['code'],
    'host'=>$room['host'],
    'phase'=>isset($room['phase'])?$room['phase']:'lobby',
    'settings'=>isset($room['settings'])?$room['settings']:array(),
    'seed'=>isset($room['seed'])?$room['seed']:0,
    'players'=>$players,
    'chat'=>$chat,
    'events'=>$events,
    'enemies'=>isset($room['enemies'])?$room['enemies']:array(),
    'cid'=>isset($room['cid'])?$room['cid']:0,
    'eid'=>isset($room['eid'])?$room['eid']:0
  );
}

// takarítás: régi szoba-fájlok törlése (olcsó, ~1%-ban fut)
function sweep_old(){
  global $DIR, $ROOM_TTL;
  if (mt_rand(1,100) > 3) return;
  $files = @glob($DIR.'/*.json');
  if (!$files) return;
  $t = time();
  foreach ($files as $f){ if (($t - @filemtime($f)) > $ROOM_TTL) @unlink($f); }
}

$a = isset($_GET['a']) ? $_GET['a'] : '';
$in = body_json();
sweep_old();

if ($a === 'create'){
  $name = clean_str(isset($in['name'])?$in['name']:'', 18); if ($name==='') $name='Kapitány';
  $seed = isset($in['seed']) ? (intval($in['seed']) & 0x7fffffff) : (mt_rand(1, 2147483000));
  if ($seed <= 0) $seed = mt_rand(1, 2147483000);
  $pid = gen_id('p'); $token = gen_id('t');
  // egyedi kód keresése
  $code = ''; for ($try=0;$try<12;$try++){ $c = gen_code(); if (!file_exists(room_path($c))){ $code=$c; break; } }
  if ($code===''){ jerr('no_code'); }
  $t = now_s();
  $room = array(
    'code'=>$code, 'host'=>$pid, 'created'=>$t, 'seed'=>$seed,
    'phase'=>'lobby',
    'settings'=>array('bots'=>1, 'pvp'=>0, 'maxPlayers'=>6, 'botCount'=>8),
    'players'=>array(),
    'chat'=>array(), 'events'=>array(), 'enemies'=>array(),
    'cid'=>0, 'eid'=>0
  );
  $room['players'][$pid] = array('name'=>$name,'token'=>$token,'ready'=>0,'color'=>0,
    'alive'=>1,'joined'=>$t,'last'=>$t,'st'=>null,'ping'=>0);
  $out = with_room($code, true, function($r) use ($room, $name){
    push_chat($room, '', $name.' létrehozta a szobát ⚓', true);
    return array('room'=>$room, 'out'=>array('ok'=>true,'code'=>$room['code'],'pid'=>$room['host'],
      'token'=>$room['players'][$room['host']]['token'],'seed'=>$room['seed'],'settings'=>$room['settings']));
  });
  jout($out);
}

if ($a === 'join'){
  $code = strtoupper(clean_str(isset($in['code'])?$in['code']:'', 8));
  $name = clean_str(isset($in['name'])?$in['name']:'', 18); if ($name==='') $name='Matróz';
  if (room_path($code)===false || !file_exists(room_path($code))) jerr('no_room');
  $pid = gen_id('p'); $token = gen_id('t');
  $out = with_room($code, false, function($r) use ($pid,$token,$name){
    if ($r===null) return array('out'=>array('ok'=>false,'err'=>'no_room'));
    $del = prune_room($r);
    if ($del) return array('delete'=>true, 'out'=>array('ok'=>false,'err'=>'no_room'));
    $max = isset($r['settings']['maxPlayers'])?intval($r['settings']['maxPlayers']):6;
    if (count($r['players']) >= $max) return array('room'=>$r,'out'=>array('ok'=>false,'err'=>'full'));
    // szín kiosztása: első szabad index
    $used = array(); foreach ($r['players'] as $p){ $used[intval($p['color'])]=1; }
    $col = 0; for ($i=0;$i<$max+2;$i++){ if (!isset($used[$i])){ $col=$i; break; } }
    $t = now_s();
    $r['players'][$pid] = array('name'=>$name,'token'=>$token,'ready'=>0,'color'=>$col,
      'alive'=>1,'joined'=>$t,'last'=>$t,'st'=>null,'ping'=>0);
    push_chat($r, '', $name.' csatlakozott 🏴‍☠️', true);
    return array('room'=>$r, 'out'=>array('ok'=>true,'code'=>$r['code'],'pid'=>$pid,'token'=>$token,
      'seed'=>$r['seed'],'settings'=>$r['settings'],'phase'=>$r['phase']));
  });
  jout($out);
}

if ($a === 'poll'){
  $code = strtoupper(clean_str(isset($in['code'])?$in['code']:'', 8));
  $pid  = isset($in['pid'])?$in['pid']:'';
  $token= isset($in['token'])?$in['token']:'';
  $csince = isset($in['csince'])?intval($in['csince']):0;
  $esince = isset($in['esince'])?intval($in['esince']):0;
  $out = with_room($code, false, function($r) use ($in,$pid,$token,$csince,$esince){
    if ($r===null) return array('out'=>array('ok'=>false,'err'=>'no_room'));
    if (!isset($r['players'][$pid]) || $r['players'][$pid]['token'] !== $token){
      // token nem stimmel VAGY kiestünk (TTL) — jelezzük, a kliens újracsatlakozhat
      $del = prune_room($r);
      if ($del) return array('delete'=>true,'out'=>array('ok'=>false,'err'=>'kicked'));
      return array('room'=>$r,'out'=>array('ok'=>false,'err'=>'kicked'));
    }
    $t = now_s();
    $me =& $r['players'][$pid];
    $me['last'] = $t;
    if (isset($in['st']) && is_array($in['st'])) $me['st'] = $in['st'];
    if (isset($in['ready'])) $me['ready'] = $in['ready'] ? 1 : 0;
    if (isset($in['color'])) $me['color'] = intval($in['color']);
    if (isset($in['name'])){ $nm=clean_str($in['name'],18); if($nm!=='') $me['name']=$nm; }
    if (isset($in['ping'])) $me['ping'] = intval($in['ping']);
    if (isset($in['alive'])) $me['alive'] = $in['alive'] ? 1 : 0;
    // chat üzenetek
    if (isset($in['chat']) && is_array($in['chat'])){
      foreach ($in['chat'] as $c){
        $m = clean_str(isset($c['m'])?$c['m']:(is_string($c)?$c:''), 160);
        if ($m!=='') push_chat($r, $me['name'], $m, false);
      }
    }
    // események (PvP-találat, kill, taunt) — bárki emittálhat
    if (isset($in['events']) && is_array($in['events'])){
      foreach ($in['events'] as $ev){
        if (!is_array($ev)) continue;
        $ne = array('to'=>isset($ev['to'])?clean_str($ev['to'],40):'all',
                    'type'=>clean_str(isset($ev['type'])?$ev['type']:'', 16),
                    'by'=>$me['name']);
        if (isset($ev['dmg'])) $ne['dmg'] = floatval($ev['dmg']);
        if (isset($ev['id']))  $ne['id']  = clean_str($ev['id'],40);
        if (isset($ev['x']))   $ne['x']   = floatval($ev['x']);
        if (isset($ev['z']))   $ne['z']   = floatval($ev['z']);
        if (isset($ev['msg'])) $ne['msg'] = clean_str($ev['msg'],80);
        if ($ne['type']!=='') push_event($r, $ne);
      }
    }
    $isHost = ($pid === $r['host']);
    // host-only: beállítások, indítás, botok broadcast, kick
    if ($isHost){
      if (isset($in['settings']) && is_array($in['settings'])){
        $s =& $r['settings'];
        if (isset($in['settings']['bots'])) $s['bots'] = $in['settings']['bots']?1:0;
        if (isset($in['settings']['pvp']))  $s['pvp']  = $in['settings']['pvp']?1:0;
        if (isset($in['settings']['maxPlayers'])){ $mp=intval($in['settings']['maxPlayers']); $s['maxPlayers']=max(2,min($GLOBALS['MAX_PLAYERS_HARD'],$mp)); }
        if (isset($in['settings']['botCount'])){ $s['botCount']=max(0,min(20,intval($in['settings']['botCount']))); }
        unset($s);
      }
      if (isset($in['start']) && $in['start'] && $r['phase']==='lobby'){
        $r['phase']='playing';
        push_chat($r, '', '⛵ A meccs elindult! Jó vadászatot!', true);
      }
      if (isset($in['enemies']) && is_array($in['enemies'])){
        $r['enemies'] = array_slice($in['enemies'], 0, 30);
      }
      if (isset($in['kick'])){
        $k = $in['kick'];
        if (isset($r['players'][$k]) && $k !== $pid){
          $kn = $r['players'][$k]['name'];
          unset($r['players'][$k]);
          push_chat($r, '', $kn.' ki lett rúgva', true);
          push_event($r, array('to'=>$k,'type'=>'kicked','by'=>$me['name']));
        }
      }
    }
    unset($me);
    $del = prune_room($r);
    if ($del) return array('delete'=>true, 'out'=>array('ok'=>false,'err'=>'gone'));
    $snap = snapshot($r, $pid, $csince, $esince);
    return array('room'=>$r, 'out'=>$snap);
  });
  jout($out);
}

if ($a === 'leave'){
  $code = strtoupper(clean_str(isset($in['code'])?$in['code']:'', 8));
  $pid  = isset($in['pid'])?$in['pid']:'';
  $token= isset($in['token'])?$in['token']:'';
  $out = with_room($code, false, function($r) use ($pid,$token){
    if ($r===null) return array('out'=>array('ok'=>true));
    // token-gate: csak a SAJÁT tokennel lehet kilépni → egy pid ismerete önmagában nem elég másokat kidobni
    if (isset($r['players'][$pid]) && $r['players'][$pid]['token'] === $token){
      $nm = $r['players'][$pid]['name'];
      unset($r['players'][$pid]);
      push_chat($r, '', $nm.' elhagyta a szobát', true);
    }
    $del = prune_room($r);
    if ($del) return array('delete'=>true, 'out'=>array('ok'=>true));
    return array('room'=>$r, 'out'=>array('ok'=>true));
  });
  jout($out);
}

jerr('bad_action');
