<?php
require __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') verify_csrf();

try {
    $pdo = db();
    $me = current_user();

    if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone = preg_replace('/[^0-9+]/', '', trim((string)($_POST['phone'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) json_response(['ok'=>false,'error'=>'Name must be 2–60 characters.'], 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) json_response(['ok'=>false,'error'=>'Enter a valid email address.'], 422);
        if (!preg_match('/^\+?[0-9]{7,15}$/', $phone)) json_response(['ok'=>false,'error'=>'Enter a phone number with 7–15 digits, optionally starting with +.'], 422);
        if (strlen($password) < 8) json_response(['ok'=>false,'error'=>'Password must be at least 8 characters.'], 422);
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? OR phone_number = ?');
        $stmt->execute([$email, $phone]);
        if ($stmt->fetch()) json_response(['ok'=>false,'error'=>'That email or phone number is already registered.'], 409);
        $stmt = $pdo->prepare('INSERT INTO users (name,email,phone_number,password_hash,last_seen) VALUES (?,?,?,?,NOW())');
        $stmt->execute([$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT)]);
        session_regenerate_id(true);
        $_SESSION['user'] = ['id'=>(int)$pdo->lastInsertId(), 'name'=>$name, 'email'=>$email, 'phone'=>$phone];
        json_response(['ok'=>true]);
    }

    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $password = (string)($_POST['password'] ?? '');
        $stmt = $pdo->prepare(
            'SELECT id, name, email, password_hash, account_status
             FROM users WHERE email = ? LIMIT 1'
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            json_response(['ok' => false, 'error' => 'Email or password is incorrect.'], 401);
        }
        if (($row['account_status'] ?? 'active') !== 'active') {
            $message = ($row['account_status'] ?? '') === 'banned'
                ? 'This account has been banned. Contact an administrator.'
                : 'This account is temporarily frozen. Contact an administrator.';
            json_response(['ok' => false, 'error' => $message], 403);
        }
        session_regenerate_id(true);
        $_SESSION['user'] = ['id'=>(int)$row['id'], 'name'=>$row['name'], 'email'=>$row['email']];
        $pdo->prepare('UPDATE users SET last_seen = NOW() WHERE id = ?')->execute([$row['id']]);
        json_response(['ok'=>true]);
    }

    if (!$me) json_response(['ok'=>false,'error'=>'Please sign in first.'], 401);
    $myId = (int)$me['id'];
    $stateStmt = $pdo->prepare('SELECT account_status FROM users WHERE id = ? LIMIT 1');
    $stateStmt->execute([$myId]);
    $state = $stateStmt->fetchColumn();
    if ($state !== 'active') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $cookie = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
        }
        session_destroy();
        json_response(['ok' => false, 'error' => $state === 'banned' ? 'This account has been banned.' : 'This account is temporarily frozen.'], 403);
    }
    $pdo->prepare('UPDATE users SET last_seen = NOW() WHERE id = ?')->execute([$myId]);

    if ($action === 'contacts' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $q = trim((string)($_GET['q'] ?? ''));
        $sql = "SELECT u.id,u.name,u.phone_number,u.last_seen,u.profile_image,
                (SELECT m.body FROM messages m WHERE (m.sender_id = ? AND m.receiver_id = u.id) OR (m.sender_id = u.id AND m.receiver_id = ?) ORDER BY m.id DESC LIMIT 1) AS last_message,
                (SELECT m.created_at FROM messages m WHERE (m.sender_id = ? AND m.receiver_id = u.id) OR (m.sender_id = u.id AND m.receiver_id = ?) ORDER BY m.id DESC LIMIT 1) AS last_time
                FROM users u WHERE u.id <> ?";
        $params = [$myId,$myId,$myId,$myId,$myId];
        if ($q !== '') {
            $sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.phone_number LIKE ?)";
            $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%'; $params[] = '%' . $q . '%';
        } else {
            $sql .= " AND EXISTS (SELECT 1 FROM messages m WHERE (m.sender_id = ? AND m.receiver_id = u.id) OR (m.sender_id = u.id AND m.receiver_id = ?))";
            $params[] = $myId; $params[] = $myId;
        }
        $sql .= " ORDER BY COALESCE(last_time, u.last_seen) DESC, u.name ASC LIMIT 100";
        $stmt = $pdo->prepare($sql); $stmt->execute($params);
        $contacts = $stmt->fetchAll();
        foreach ($contacts as &$c) {
            $c['id'] = (int)$c['id'];
            $c['online'] = strtotime((string)$c['last_seen']) > time() - 90;
        }
        json_response(['ok'=>true,'contacts'=>$contacts]);
    }

    if ($action === 'messages' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $other = filter_input(INPUT_GET, 'user_id', FILTER_VALIDATE_INT);
        $after = max(0, (int)($_GET['after'] ?? 0));
        if (!$other || $other === $myId) json_response(['ok'=>false,'error'=>'Choose a valid contact.'], 422);
        $stmt = $pdo->prepare('SELECT id,name,phone_number FROM users WHERE id = ?'); $stmt->execute([$other]);
        $contact = $stmt->fetch();
        if (!$contact) json_response(['ok'=>false,'error'=>'Contact not found.'], 404);
        $pdo->prepare('UPDATE messages SET read_at=NOW() WHERE sender_id=? AND receiver_id=? AND read_at IS NULL')->execute([$other,$myId]);
        $stmt = $pdo->prepare('SELECT id,sender_id,receiver_id,body,attachment_path,attachment_name,attachment_mime,created_at,read_at FROM messages WHERE ((sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)) AND id>? ORDER BY id ASC LIMIT 200');
        $stmt->execute([$myId,$other,$other,$myId,$after]);
        json_response(['ok'=>true,'contact'=>$contact,'messages'=>$stmt->fetchAll()]);
    }

    if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $other = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $body = trim((string)($_POST['body'] ?? ''));
        if (!$other || $other === $myId) json_response(['ok'=>false,'error'=>'Choose a valid contact.'], 422);
        if ($body === '' || mb_strlen($body) > 4000) json_response(['ok'=>false,'error'=>'Message must be between 1 and 4000 characters.'], 422);
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?'); $stmt->execute([$other]);
        if (!$stmt->fetch()) json_response(['ok'=>false,'error'=>'Contact not found.'], 404);
        $stmt = $pdo->prepare('INSERT INTO messages (sender_id,receiver_id,body) VALUES (?,?,?)');
        $stmt->execute([$myId,$other,$body]);
        json_response(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
    }


    if ($action === 'profile' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = preg_replace('/[^0-9+]/', '', trim((string)($_POST['phone'] ?? '')));
        if (!preg_match('/^\+?[0-9]{7,15}$/', $phone)) json_response(['ok'=>false,'error'=>'Enter a phone number with 7–15 digits, optionally starting with +.'], 422);
        $stmt = $pdo->prepare('SELECT id FROM users WHERE phone_number=? AND id<>?');
        $stmt->execute([$phone, $myId]);
        if ($stmt->fetch()) json_response(['ok'=>false,'error'=>'That phone number is already used by another account.'], 409);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) json_response(['ok'=>false,'error'=>'Name must be 2–60 characters.'], 422);
        $imagePath = null;
        if (!empty($_FILES['avatar']['name'])) {
            $file = $_FILES['avatar'];
            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 3 * 1024 * 1024) json_response(['ok'=>false,'error'=>'Profile image must be under 3 MB.'], 422);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            if (!isset($allowed[$mime])) json_response(['ok'=>false,'error'=>'Use JPG, PNG, or WEBP for profile images.'], 422);
            $dir = __DIR__ . '/uploads';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $filename = 'avatar_' . $myId . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
            if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) json_response(['ok'=>false,'error'=>'Could not save profile image.'], 500);
            $imagePath = 'uploads/' . $filename;
        }
        if ($imagePath) $pdo->prepare('UPDATE users SET name=?,phone_number=?,profile_image=? WHERE id=?')->execute([$name,$phone,$imagePath,$myId]);
        else $pdo->prepare('UPDATE users SET name=?,phone_number=? WHERE id=?')->execute([$name,$phone,$myId]);
        $_SESSION['user']['name'] = $name; $_SESSION['user']['phone'] = $phone;
        json_response(['ok'=>true,'name'=>$name,'phone'=>$phone,'profile_image'=>$imagePath]);
    }

    if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $other = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$other || $other === $myId) json_response(['ok'=>false,'error'=>'Choose a valid contact.'], 422);
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id=?'); $stmt->execute([$other]);
        if (!$stmt->fetch()) json_response(['ok'=>false,'error'=>'Contact not found.'], 404);
        if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) json_response(['ok'=>false,'error'=>'Choose a file to upload.'], 422);
        $file = $_FILES['file'];
        if ($file['size'] > 15 * 1024 * 1024) json_response(['ok'=>false,'error'=>'Files must be 15 MB or smaller.'], 422);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed = ['image/jpeg','image/png','image/gif','image/webp','video/mp4','video/webm','application/pdf','text/plain','application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        if (!in_array($mime, $allowed, true)) json_response(['ok'=>false,'error'=>'That file type is not allowed in this demo.'], 422);
        $dir = __DIR__ . '/uploads';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $original = basename((string)$file['name']);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $filename = 'file_' . $myId . '_' . bin2hex(random_bytes(12)) . ($ext ? '.' . $ext : '');
        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) json_response(['ok'=>false,'error'=>'Could not save file.'], 500);
        $path = 'uploads/' . $filename;
        $stmt = $pdo->prepare('INSERT INTO messages (sender_id,receiver_id,body,attachment_path,attachment_name,attachment_mime) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$myId,$other,'',$path,$original,$mime]);
        json_response(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
    }

    if ($action === 'signal' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $other = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $callKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['call_key'] ?? ''));
        $type = (string)($_POST['signal_type'] ?? '');
        $payload = (string)($_POST['payload'] ?? '');
        if (!$other || $other === $myId || strlen($callKey) < 8 || strlen($callKey) > 100) json_response(['ok'=>false,'error'=>'Invalid call details.'], 422);
        if (!in_array($type, ['offer','answer','ice','hangup','decline','ring'], true)) json_response(['ok'=>false,'error'=>'Invalid call signal.'], 422);
        if (strlen($payload) > 50000) json_response(['ok'=>false,'error'=>'Call signal is too large.'], 422);
        $stmt = $pdo->prepare('SELECT id FROM users WHERE id=?'); $stmt->execute([$other]);
        if (!$stmt->fetch()) json_response(['ok'=>false,'error'=>'Contact not found.'], 404);
        $stmt = $pdo->prepare('INSERT INTO call_signals (call_key,sender_id,receiver_id,signal_type,payload) VALUES (?,?,?,?,?)');
        $stmt->execute([$callKey,$myId,$other,$type,$payload]);
        json_response(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
    }

    if ($action === 'signals' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $callKey = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_GET['call_key'] ?? ''));
        $after = max(0, (int)($_GET['after'] ?? 0));
        if (strlen($callKey) < 8) json_response(['ok'=>false,'error'=>'Invalid call key.'], 422);
        $stmt = $pdo->prepare('SELECT id,sender_id,signal_type,payload,created_at FROM call_signals WHERE call_key=? AND receiver_id=? AND id>? ORDER BY id ASC LIMIT 100');
        $stmt->execute([$callKey,$myId,$after]);
        json_response(['ok'=>true,'signals'=>$stmt->fetchAll()]);
    }


    if ($action === 'incoming_calls' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        // Only recent ring signals addressed to the signed-in user are eligible.
        // A client-side dedupe prevents repeated prompts for the same call.
        $stmt = $pdo->prepare(
            "SELECT s.id,s.call_key,s.sender_id,s.payload,s.created_at,u.name,u.profile_image
             FROM call_signals s
             JOIN users u ON u.id=s.sender_id
             WHERE s.receiver_id=? AND s.signal_type='ring'
               AND s.created_at >= (NOW() - INTERVAL 35 SECOND)
             ORDER BY s.id DESC LIMIT 10"
        );
        $stmt->execute([$myId]);
        json_response(['ok'=>true,'calls'=>$stmt->fetchAll()]);
    }

    json_response(['ok'=>false,'error'=>'Unknown action.'], 404);
} catch (PDOException $e) {
    error_log('ChatWave database error: ' . $e->getMessage());
    json_response(['ok'=>false,'error'=>'Database connection failed. Check config.php and import database.sql.'], 500);
}
