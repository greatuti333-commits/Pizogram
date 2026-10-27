<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
$action = (string)($_GET['action'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') verify_csrf();
try {
  $pdo = db();
  $me = current_user();
  $public = ['recovery_start','recovery_verify'];
  if (!in_array($action, $public, true)) {
    if (!$me) json_response(['ok'=>false,'error'=>'Please sign in first.'],401);
    $myId=(int)$me['id'];
    $check=$pdo->prepare("SELECT account_status FROM users WHERE id=?"); $check->execute([$myId]); $state=$check->fetchColumn();
    if ($state !== 'active') { session_destroy(); json_response(['ok'=>false,'error'=>$state==='banned'?'This account is banned.':'This account is temporarily frozen.'],403); }
  }
  if ($action === 'recovery_start' && $_SERVER['REQUEST_METHOD']==='POST') {
    $identity=trim((string)($_POST['identity']??''));
    if ($identity==='') json_response(['ok'=>false,'error'=>'Enter your registered email or phone number.'],422);
    $st=$pdo->prepare('SELECT id,email,phone_number FROM users WHERE email=? OR phone_number=? LIMIT 1'); $st->execute([strtolower($identity),$identity]); $u=$st->fetch();
    // Keep response generic so the endpoint does not disclose whether an account exists.
    if ($u) {
      $recent=$pdo->prepare('SELECT COUNT(*) FROM recovery_codes WHERE user_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'); $recent->execute([$u['id']]);
      if ((int)$recent->fetchColumn() >= 3) json_response(['ok'=>false,'error'=>'Too many codes requested. Try again in 15 minutes.'],429);
      $code=(string)random_int(100000,999999); $hash=hash('sha256',(string)$u['id'].':'.$code.':'.session_id());
      $pdo->prepare('INSERT INTO recovery_codes (user_id,code_hash,expires_at) VALUES (?,?,DATE_ADD(NOW(), INTERVAL 10 MINUTE))')->execute([$u['id'],$hash]);
      if (filter_var($u['email'],FILTER_VALIDATE_EMAIL) && str_contains($identity,'@')) {
        $from=getenv('CHATWAVE_MAIL_FROM') ?: 'no-reply@localhost';
        $sent=@mail($u['email'],'Your ChatWave verification code',"Your ChatWave sign-in verification code is: $code\nIt expires in 10 minutes. If you did not request this, ignore this message.","From: $from\r\nContent-Type: text/plain; charset=UTF-8");
        if (!$sent) json_response(['ok'=>false,'error'=>'Email delivery is not configured on this PHP server. Configure SMTP/mail delivery, then try again.'],503);
      } else {
        // SMS is intentionally not simulated or exposed in the response; configure a real SMS provider before enabling phone recovery.
        json_response(['ok'=>false,'error'=>'SMS delivery is not configured yet. Configure an SMS provider to send codes to phone numbers.'],503);
      }
    }
    json_response(['ok'=>true,'message'=>'If the account exists, a verification code has been sent to its registered email.']);
  }
  if ($action === 'recovery_verify' && $_SERVER['REQUEST_METHOD']==='POST') {
    $identity=trim((string)($_POST['identity']??'')); $code=preg_replace('/\D/','',(string)($_POST['code']??''));
    if (!preg_match('/^\d{6}$/',$code)) json_response(['ok'=>false,'error'=>'Enter the six-digit code.'],422);
    $st=$pdo->prepare('SELECT id,email,phone_number,name,password_hash,account_status FROM users WHERE email=? OR phone_number=? LIMIT 1'); $st->execute([strtolower($identity),$identity]); $u=$st->fetch();
    if (!$u || $u['account_status']!=='active') json_response(['ok'=>false,'error'=>'The code is invalid or expired.'],422);
    $st=$pdo->prepare('SELECT id,code_hash,attempts FROM recovery_codes WHERE user_id=? AND used_at IS NULL AND expires_at>NOW() ORDER BY id DESC LIMIT 1'); $st->execute([$u['id']]); $row=$st->fetch();
    if (!$row || (int)$row['attempts']>=5) json_response(['ok'=>false,'error'=>'The code is invalid or expired.'],422);
    $hash=hash('sha256',(string)$u['id'].':'.$code.':'.session_id());
    if (!hash_equals($row['code_hash'],$hash)) { $pdo->prepare('UPDATE recovery_codes SET attempts=attempts+1 WHERE id=?')->execute([$row['id']]); json_response(['ok'=>false,'error'=>'The code is invalid or expired.'],422); }
    $pdo->prepare('UPDATE recovery_codes SET used_at=NOW() WHERE id=?')->execute([$row['id']]);
    session_regenerate_id(true); $_SESSION['user']=['id'=>(int)$u['id'],'name'=>$u['name'],'email'=>$u['email'],'phone'=>$u['phone_number']]; $_SESSION['csrf']=bin2hex(random_bytes(32));
    json_response(['ok'=>true,'message'=>'Verification successful. Signing you in.']);
  }
  $myId=(int)$me['id'];
  if ($action==='settings' && $_SERVER['REQUEST_METHOD']==='GET') {
    $s=$pdo->prepare('SELECT username,description,read_receipts,status_visibility,online_visibility,away_message,profile_image,role FROM users WHERE id=?');$s->execute([$myId]);json_response(['ok'=>true,'settings'=>$s->fetch()]);
  }
  if ($action==='settings' && $_SERVER['REQUEST_METHOD']==='POST') {
    $username=trim((string)($_POST['username']??'')); $description=trim((string)($_POST['description']??'')); $away=trim((string)($_POST['away_message']??''));
    $rr=($_POST['read_receipts']??'1')==='1'?1:0; $sv=(string)($_POST['status_visibility']??'contacts');$ov=(string)($_POST['online_visibility']??'everyone');
    if ($username!=='' && !preg_match('/^[a-zA-Z0-9_.]{3,40}$/',$username)) json_response(['ok'=>false,'error'=>'Username must be 3–40 characters (letters, numbers, _ or .).'],422);
    if (!in_array($sv,['everyone','contacts','nobody'],true)||!in_array($ov,['everyone','contacts','nobody'],true)) json_response(['ok'=>false,'error'=>'Invalid privacy setting.'],422);
    if (mb_strlen($description)>280||mb_strlen($away)>180) json_response(['ok'=>false,'error'=>'Description must be 280 characters or less and away message 180 or less.'],422);
    try {$pdo->prepare('UPDATE users SET username=NULLIF(?,\'\'),description=?,read_receipts=?,status_visibility=?,online_visibility=?,away_message=? WHERE id=?')->execute([$username,$description,$rr,$sv,$ov,$away,$myId]);} catch(PDOException $e){if((string)$e->getCode()==='23000')json_response(['ok'=>false,'error'=>'That username is already taken.'],409);throw $e;}
    json_response(['ok'=>true,'message'=>'Settings saved.']);
  }
  if ($action==='groups' && $_SERVER['REQUEST_METHOD']==='GET') {
    $st=$pdo->prepare('SELECT g.id,g.name,g.description,g.created_at,(SELECT COUNT(*) FROM group_members gm2 WHERE gm2.group_id=g.id) member_count FROM chat_groups g JOIN group_members gm ON gm.group_id=g.id WHERE gm.user_id=? ORDER BY g.created_at DESC');$st->execute([$myId]);json_response(['ok'=>true,'groups'=>$st->fetchAll()]);
  }
  if ($action==='group_create' && $_SERVER['REQUEST_METHOD']==='POST') {
    $name=trim((string)($_POST['name']??''));$desc=trim((string)($_POST['description']??''));$members=$_POST['members']??[]; if(!is_array($members))$members=[];
    if(mb_strlen($name)<2||mb_strlen($name)>80)json_response(['ok'=>false,'error'=>'Group name must be 2–80 characters.'],422);
    $pdo->beginTransaction();$pdo->prepare('INSERT INTO chat_groups(name,description,created_by) VALUES(?,?,?)')->execute([$name,$desc,$myId]);$gid=(int)$pdo->lastInsertId();$pdo->prepare("INSERT INTO group_members(group_id,user_id,member_role) VALUES(?,?,'admin')")->execute([$gid,$myId]);
    $add=$pdo->prepare("INSERT IGNORE INTO group_members(group_id,user_id,member_role) SELECT ?,id,'member' FROM users WHERE id=? AND account_status='active'");foreach(array_unique(array_map('intval',$members)) as $mid){if($mid>0&&$mid!==$myId)$add->execute([$gid,$mid]);}$pdo->commit();json_response(['ok'=>true,'group_id'=>$gid]);
  }
  if ($action==='group_members' && $_SERVER['REQUEST_METHOD']==='GET') {
    $gid=(int)($_GET['group_id']??0);$st=$pdo->prepare('SELECT 1 FROM group_members WHERE group_id=? AND user_id=?');$st->execute([$gid,$myId]);if(!$st->fetch())json_response(['ok'=>false,'error'=>'You are not a member of this group.'],403);
    $st=$pdo->prepare('SELECT u.id,u.name,u.username,gm.member_role FROM group_members gm JOIN users u ON u.id=gm.user_id WHERE gm.group_id=? ORDER BY gm.joined_at');$st->execute([$gid]);json_response(['ok'=>true,'members'=>$st->fetchAll()]);
  }
  if ($action==='group_add' && $_SERVER['REQUEST_METHOD']==='POST') {
    $gid=(int)($_POST['group_id']??0);$uid=(int)($_POST['user_id']??0);$st=$pdo->prepare("SELECT 1 FROM group_members WHERE group_id=? AND user_id=? AND member_role='admin'");$st->execute([$gid,$myId]);if(!$st->fetch())json_response(['ok'=>false,'error'=>'Only group admins can add members.'],403);$pdo->prepare("INSERT IGNORE INTO group_members(group_id,user_id,member_role) SELECT ?,id,'member' FROM users WHERE id=? AND account_status='active'")->execute([$gid,$uid]);json_response(['ok'=>true]);
  }
  if ($action==='group_messages' && $_SERVER['REQUEST_METHOD']==='GET') {
    $gid=(int)($_GET['group_id']??0);$after=max(0,(int)($_GET['after']??0));$st=$pdo->prepare('SELECT 1 FROM group_members WHERE group_id=? AND user_id=?');$st->execute([$gid,$myId]);if(!$st->fetch())json_response(['ok'=>false,'error'=>'You are not a member of this group.'],403);$st=$pdo->prepare('SELECT gm.id,gm.body,gm.created_at,u.id sender_id,u.name FROM group_messages gm JOIN users u ON u.id=gm.sender_id WHERE gm.group_id=? AND gm.id>? ORDER BY gm.id LIMIT 200');$st->execute([$gid,$after]);json_response(['ok'=>true,'messages'=>$st->fetchAll()]);
  }
  if ($action==='group_send' && $_SERVER['REQUEST_METHOD']==='POST') {
    $gid=(int)($_POST['group_id']??0);$body=trim((string)($_POST['body']??''));$st=$pdo->prepare('SELECT 1 FROM group_members WHERE group_id=? AND user_id=?');$st->execute([$gid,$myId]);if(!$st->fetch())json_response(['ok'=>false,'error'=>'You are not a member of this group.'],403);if($body===''||mb_strlen($body)>4000)json_response(['ok'=>false,'error'=>'Message must be 1–4000 characters.'],422);$pdo->prepare('INSERT INTO group_messages(group_id,sender_id,body) VALUES(?,?,?)')->execute([$gid,$myId,$body]);json_response(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
  }
  if ($action==='status_list' && $_SERVER['REQUEST_METHOD']==='GET') {
    $st=$pdo->prepare("SELECT s.id,s.user_id,s.caption,s.media_path,s.media_mime,s.created_at,s.expires_at,u.name,u.username,u.profile_image,(SELECT COUNT(*) FROM status_reactions r WHERE r.status_id=s.id) likes,(SELECT COUNT(*) FROM status_reactions r WHERE r.status_id=s.id AND r.user_id=?) liked FROM statuses s JOIN users u ON u.id=s.user_id WHERE s.expires_at>NOW() AND s.user_id<>? AND (u.status_visibility='everyone' OR (u.status_visibility='contacts' AND EXISTS(SELECT 1 FROM messages m WHERE (m.sender_id=? AND m.receiver_id=u.id) OR (m.sender_id=u.id AND m.receiver_id=?)))) ORDER BY s.created_at DESC LIMIT 100");$st->execute([$myId,$myId,$myId,$myId]);$rows=$st->fetchAll();$mine=$pdo->prepare('SELECT id,user_id,caption,media_path,media_mime,created_at,expires_at,(SELECT COUNT(*) FROM status_reactions r WHERE r.status_id=statuses.id) likes,(SELECT COUNT(*) FROM status_reactions r WHERE r.status_id=statuses.id AND r.user_id=?) liked FROM statuses WHERE user_id=? AND expires_at>NOW() ORDER BY created_at DESC');$mine->execute([$myId,$myId]);json_response(['ok'=>true,'statuses'=>array_merge($mine->fetchAll(),$rows)]);
  }
  if ($action==='voice_note' && $_SERVER['REQUEST_METHOD']==='POST') {
    $other=(int)($_POST['user_id']??0); if(!$other||$other===$myId)json_response(['ok'=>false,'error'=>'Choose a contact first.'],422);
    $st=$pdo->prepare("SELECT id FROM users WHERE id=? AND account_status='active'");$st->execute([$other]);if(!$st->fetch())json_response(['ok'=>false,'error'=>'Contact not found or unavailable.'],404);
    if(empty($_FILES['file'])||$_FILES['file']['error']!==UPLOAD_ERR_OK)json_response(['ok'=>false,'error'=>'Record a voice note first.'],422);$f=$_FILES['file'];if($f['size']>15*1024*1024)json_response(['ok'=>false,'error'=>'Voice note is too large.'],422);$mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);$allowed=['audio/webm'=>'webm','audio/ogg'=>'ogg','audio/mpeg'=>'mp3','audio/mp4'=>'m4a','application/octet-stream'=>'webm'];if(!isset($allowed[$mime]))json_response(['ok'=>false,'error'=>'Unsupported audio format. Use a browser that records WebM or Ogg audio.'],422);$dir=__DIR__.'/uploads';if(!is_dir($dir))mkdir($dir,0755,true);$fn='voice_'.$myId.'_'.bin2hex(random_bytes(12)).'.'.$allowed[$mime];if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$fn))json_response(['ok'=>false,'error'=>'Could not save voice note.'],500);$path='uploads/'.$fn;$pdo->prepare('INSERT INTO messages(sender_id,receiver_id,body,attachment_path,attachment_name,attachment_mime) VALUES(?,?,?, ?,?,?)')->execute([$myId,$other,'Voice note',$path,'Voice note',$mime]);json_response(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
  }
  if ($action==='status_upload' && $_SERVER['REQUEST_METHOD']==='POST') {
    $caption=trim((string)($_POST['caption']??''));if(mb_strlen($caption)>500)json_response(['ok'=>false,'error'=>'Caption must be 500 characters or less.'],422);$path=null;$mime=null;
    if(!empty($_FILES['media']['name'])){$f=$_FILES['media'];if($f['error']!==UPLOAD_ERR_OK||$f['size']>15*1024*1024)json_response(['ok'=>false,'error'=>'Status media must be under 15 MB.'],422);$mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);$allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','video/mp4'=>'mp4','video/webm'=>'webm'];if(!isset($allowed[$mime]))json_response(['ok'=>false,'error'=>'Use JPG, PNG, WEBP, MP4 or WEBM.'],422);$dir=__DIR__.'/uploads';if(!is_dir($dir))mkdir($dir,0755,true);$fn='status_'.$myId.'_'.bin2hex(random_bytes(12)).'.'.$allowed[$mime];if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$fn))json_response(['ok'=>false,'error'=>'Could not save status media.'],500);$path='uploads/'.$fn;}
    if($caption===''&&!$path)json_response(['ok'=>false,'error'=>'Add text or choose a photo/video.'],422);$pdo->prepare('INSERT INTO statuses(user_id,caption,media_path,media_mime,expires_at) VALUES(?,?,?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR))')->execute([$myId,$caption,$path,$mime]);json_response(['ok'=>true]);
  }
  if ($action==='status_like' && $_SERVER['REQUEST_METHOD']==='POST') { $sid=(int)($_POST['status_id']??0);$st=$pdo->prepare('SELECT id FROM statuses WHERE id=? AND expires_at>NOW()');$st->execute([$sid]);if(!$st->fetch())json_response(['ok'=>false,'error'=>'Status not found.'],404);$st=$pdo->prepare('SELECT 1 FROM status_reactions WHERE status_id=? AND user_id=?');$st->execute([$sid,$myId]);if($st->fetch())$pdo->prepare('DELETE FROM status_reactions WHERE status_id=? AND user_id=?')->execute([$sid,$myId]);else $pdo->prepare("INSERT INTO status_reactions(status_id,user_id,reaction) VALUES(?,?,'like')")->execute([$sid,$myId]);json_response(['ok'=>true]); }
  if ($action==='status_reshare' && $_SERVER['REQUEST_METHOD']==='POST') { $sid=(int)($_POST['status_id']??0);$st=$pdo->prepare('SELECT caption,media_path,media_mime FROM statuses WHERE id=? AND expires_at>NOW()');$st->execute([$sid]);$src=$st->fetch();if(!$src)json_response(['ok'=>false,'error'=>'Status not found.'],404);$pdo->prepare('INSERT INTO statuses(user_id,caption,media_path,media_mime,expires_at) VALUES(?,?,?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR))')->execute([$myId,$src['caption'],$src['media_path'],$src['media_mime']]);$pdo->prepare('INSERT IGNORE INTO status_reshares(status_id,user_id,caption) VALUES(?,?,?)')->execute([$sid,$myId,$src['caption']]);json_response(['ok'=>true,'message'=>'Status reshared.']); }
  if ($action==='admin_users' && $_SERVER['REQUEST_METHOD']==='GET') { $st=$pdo->prepare("SELECT role FROM users WHERE id=?");$st->execute([$myId]);if($st->fetchColumn()!=='admin')json_response(['ok'=>false,'error'=>'Administrator access required.'],403);$st=$pdo->query('SELECT id,name,email,phone_number,username,account_status,role,created_at FROM users ORDER BY created_at DESC LIMIT 500');json_response(['ok'=>true,'users'=>$st->fetchAll()]); }
  if ($action==='admin_action' && $_SERVER['REQUEST_METHOD']==='POST') { $st=$pdo->prepare('SELECT role FROM users WHERE id=?');$st->execute([$myId]);if($st->fetchColumn()!=='admin')json_response(['ok'=>false,'error'=>'Administrator access required.'],403);$target=(int)($_POST['user_id']??0);$act=(string)($_POST['admin_action']??'');$reason=trim((string)($_POST['reason']??''));if($target===$myId)json_response(['ok'=>false,'error'=>'You cannot freeze or ban your own admin account.'],422);if(!in_array($act,['freeze','unfreeze','ban','unban'],true))json_response(['ok'=>false,'error'=>'Invalid admin action.'],422);$status=match($act){'freeze'=>'frozen','ban'=>'banned','unfreeze','unban'=>'active'};$pdo->prepare('UPDATE users SET account_status=? WHERE id=?')->execute([$status,$target]);$pdo->prepare('INSERT INTO admin_actions(admin_id,target_user_id,action,reason) VALUES(?,?,?,?)')->execute([$myId,$target,$act,$reason]);json_response(['ok'=>true]); }
  if ($action==='ai_chat' && $_SERVER['REQUEST_METHOD']==='POST') { $key=getenv('CHATWAVE_AI_API_KEY');$prompt=trim((string)($_POST['message']??''));if($prompt===''||mb_strlen($prompt)>3000)json_response(['ok'=>false,'error'=>'Enter a message up to 3000 characters.'],422);if(!$key)json_response(['ok'=>false,'error'=>'AI assistant is ready to connect, but CHATWAVE_AI_API_KEY is not configured on the server.'],503);if(!function_exists('curl_init'))json_response(['ok'=>false,'error'=>'Enable the PHP cURL extension to use the AI assistant.'],503);$payload=json_encode(['model'=>getenv('CHATWAVE_AI_MODEL')?:'gpt-4o-mini','messages'=>[['role'=>'system','content'=>'You are ChatWave Assistant. Be helpful, safe, concise, and do not claim access to the user account or messages unless they provide the details.'],['role'=>'user','content'=>$prompt]],'temperature'=>0.7]);$ch=curl_init('https://api.openai.com/v1/chat/completions');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>$payload,CURLOPT_TIMEOUT=>25]);$raw=curl_exec($ch);$err=curl_error($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($raw===false||$code<200||$code>=300)json_response(['ok'=>false,'error'=>'AI request failed. Check the server API key, billing, and network connection.'.($err?' Details: '.$err:'')],503);$out=json_decode($raw,true);json_response(['ok'=>true,'reply'=>$out['choices'][0]['message']['content']??'No response was returned.']); }
  json_response(['ok'=>false,'error'=>'Unknown feature action.'],404);
} catch(Throwable $e) { if(isset($pdo) && $pdo->inTransaction())$pdo->rollBack(); error_log('ChatWave features error: '.$e->getMessage()); json_response(['ok'=>false,'error'=>'A server error occurred. Check the PHP error log.'],500); }
