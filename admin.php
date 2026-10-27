<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$pdo = db();

if (empty($_SESSION['user']['id'])) {
    header('Location: index.php');
    exit;
}

// Always verify the administrator's current database permissions.
$adminId = (int) $_SESSION['user']['id'];

$stmt = $pdo->prepare(
    'SELECT id, name, email, role, account_status
     FROM users WHERE id = ? LIMIT 1'
);
$stmt->execute([$adminId]);
$admin = $stmt->fetch();

if (
    !$admin ||
    $admin['role'] !== 'admin' ||
    $admin['account_status'] !== 'active'
) {
    http_response_code(403);
    exit('Access denied. An active administrator account is required.');
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$notice = '';
$error = '';

// Process administrator actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = (string) ($_POST['csrf'] ?? '');
    $sessionToken = (string) csrf_token();

    if (
        $submittedToken === '' ||
        !hash_equals($sessionToken, $submittedToken)
    ) {
        $error = 'Security token expired. Refresh the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $targetId = filter_var(
            $_POST['user_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        if (!$targetId || $targetId < 1) {
            $error = 'Please select a valid user.';
        } elseif ($targetId === $adminId) {
            $error = 'You cannot suspend, demote, or modify your own administrator account here.';
        } else {
            // Confirm the target account exists.
            $check = $pdo->prepare(
                'SELECT id, name, email, role, account_status
                 FROM users WHERE id = ? LIMIT 1'
            );
            $check->execute([$targetId]);
            $target = $check->fetch();

            if (!$target) {
                $error = 'User account not found.';
            } else {
                try {
                    switch ($action) {
                        case 'freeze':
                            $stmt = $pdo->prepare("UPDATE users SET account_status = 'frozen' WHERE id = ?");
                            $stmt->execute([$targetId]);
                            $notice = 'User account frozen.';
                            break;

                        case 'unfreeze':
                        case 'unban':
                            $stmt = $pdo->prepare("UPDATE users SET account_status = 'active' WHERE id = ?");
                            $stmt->execute([$targetId]);
                            $notice = 'User account reactivated.';
                            break;

                        case 'ban':
                            $stmt = $pdo->prepare("UPDATE users SET account_status = 'banned' WHERE id = ?");
                            $stmt->execute([$targetId]);
                            $notice = 'User account banned.';
                            break;

                        case 'promote':
                            $stmt = $pdo->prepare(
                                "UPDATE users SET role = 'admin'
                                 WHERE id = ?"
                            );
                            $stmt->execute([$targetId]);
                            $notice = 'User promoted to administrator.';
                            break;

                        case 'demote':
                            // Never allow the system to have zero admins.
                            $count = (int) $pdo->query(
                                "SELECT COUNT(*) FROM users
                                 WHERE role = 'admin'
                                   AND account_status = 'active'"
                            )->fetchColumn();

                            if (
                                $target['role'] === 'admin' &&
                                $target['account_status'] === 'active' &&
                                $count <= 1
                            ) {
                                $error = 'You cannot demote the last active administrator.';
                            } else {
                                $stmt = $pdo->prepare(
                                    "UPDATE users SET role = 'user'
                                     WHERE id = ?"
                                );
                                $stmt->execute([$targetId]);
                                $notice = 'Administrator changed to a regular user.';
                            }
                            break;

                        default:
                            $error = 'Unknown administrator action.';
                    }
                } catch (PDOException $e) {
                    error_log('ChatWave admin action error: ' . $e->getMessage());
                    $error = 'The action could not be completed. Check the PHP error log.';
                }
            }
        }
    }
}

// Dashboard statistics.
$totalUsers = (int) $pdo->query(
    'SELECT COUNT(*) FROM users'
)->fetchColumn();

$activeUsers = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE account_status = 'active'"
)->fetchColumn();

$frozenUsers = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE account_status = 'frozen'"
)->fetchColumn();
$bannedUsers = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE account_status = 'banned'"
)->fetchColumn();

$totalAdmins = (int) $pdo->query(
    "SELECT COUNT(*) FROM users WHERE role = 'admin'"
)->fetchColumn();

// Search and filter.
$search = trim((string) ($_GET['q'] ?? ''));
$filter = (string) ($_GET['status'] ?? 'all');

$sql = 'SELECT id, name, email, phone_number, role,
               account_status, created_at
        FROM users WHERE 1=1';
$params = [];

if ($search !== '') {
    $sql .= ' AND (name LIKE ? OR email LIKE ? OR phone_number LIKE ?)';
    $term = '%' . $search . '%';
    array_push($params, $term, $term, $term);
}

if (in_array($filter, ['active', 'frozen', 'banned'], true)) {
    $sql .= ' AND account_status = ?';
    $params[] = $filter;
}

$sql .= ' ORDER BY id DESC LIMIT 200';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$csrf = h(csrf_token());
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ChatWave Admin Dashboard</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Arial,sans-serif;background:#f3f6f8;color:#17212b}
header{background:#075e54;color:white;padding:22px 5%;display:flex;justify-content:space-between;align-items:center;gap:15px;flex-wrap:wrap}
header h1{margin:0;font-size:25px}
header p{margin:6px 0 0;color:#d9f4ed}
header a{color:white;text-decoration:none;border:1px solid #ffffff80;padding:10px 14px;border-radius:8px}
main{max-width:1400px;margin:28px auto;padding:0 20px}
.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:25px}
.card{background:white;border-radius:12px;padding:22px;box-shadow:0 2px 10px #152b3b0c}
.card span{color:#687782;font-size:14px}
.card strong{display:block;font-size:30px;margin-top:10px}
.panel{background:white;border-radius:12px;padding:22px;margin-bottom:22px;box-shadow:0 2px 10px #152b3b0c}
.panel h2{margin-top:0}
.notice{background:#dcfce7;color:#166534;padding:13px;border-radius:8px;margin-bottom:16px}
.error{background:#fee2e2;color:#991b1b;padding:13px;border-radius:8px;margin-bottom:16px}
.filters{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px}
input,select{padding:11px;border:1px solid #ccd5dc;border-radius:7px;font-size:14px}
.filters input{flex:1;min-width:190px}
button{border:0;padding:9px 11px;border-radius:7px;cursor:pointer;font-weight:bold;margin:2px}
.primary{background:#075e54;color:white}
.activate{background:#dcfce7;color:#166534}
.suspend{background:#ffedd5;color:#9a3412}
.promote{background:#dbeafe;color:#1d4ed8}
.demote{background:#eee;color:#333}
table{width:100%;border-collapse:collapse}
th,td{text-align:left;padding:13px 10px;border-bottom:1px solid #e7edf0;vertical-align:top}
th{background:#f8fafb;font-size:13px;color:#50616d}
td{font-size:14px}
.badge{display:inline-block;border-radius:20px;padding:5px 9px;font-size:12px;font-weight:bold}
.active{background:#dcfce7;color:#166534}
.frozen{background:#ffedd5;color:#9a3412}.banned{background:#fee2e2;color:#991b1b}
.admin{background:#dbeafe;color:#1d4ed8}
.user{background:#eee;color:#444}
.actions{min-width:175px}
.actions form{display:inline-block}
.muted{color:#71808b;font-size:13px}
.table-wrap{overflow-x:auto}
@media(max-width:900px){.cards{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:520px){.cards{grid-template-columns:1fr}.panel{padding:14px}main{padding:0 12px}}
</style>
</head>
<body>

<header>
  <div>
    <h1>✦ ChatWave Admin</h1>
    <p>Welcome, <?= h($admin['name']) ?> · <?= h($admin['email']) ?></p>
  </div>
  <div>
    <a href="index.php">Open ChatWave</a>
    <a href="logout.php">Sign out</a>
  </div>
</header>

<main>
  <?php if ($notice !== ''): ?>
    <div class="notice"><?= h($notice) ?></div>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <div class="error"><?= h($error) ?></div>
  <?php endif; ?>

  <section class="cards">
    <div class="card"><span>Total registered users</span><strong><?= $totalUsers ?></strong></div>
    <div class="card"><span>Active accounts</span><strong><?= $activeUsers ?></strong></div>
    <div class="card"><span>Frozen accounts</span><strong><?= $frozenUsers ?></strong></div>
    <div class="card"><span>Banned accounts</span><strong><?= $bannedUsers ?></strong></div>
    <div class="card"><span>Administrators</span><strong><?= $totalAdmins ?></strong></div>
  </section>

  <section class="panel">
    <h2>User account management</h2>
    <p class="muted">Search accounts, freeze or ban access, reactivate users, and manage administrator permissions.</p>

    <form class="filters" method="get">
      <input type="search" name="q" value="<?= h($search) ?>"
             placeholder="Search name, email, or phone">
      <select name="status">
        <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>All statuses</option>
        <option value="active" <?= $filter === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="frozen" <?= $filter === 'frozen' ? 'selected' : '' ?>>Frozen</option>
        <option value="banned" <?= $filter === 'banned' ? 'selected' : '' ?>>Banned</option>
      </select>
      <button class="primary" type="submit">Search</button>
      <a href="admin.php">Clear filters</a>
    </form>

    <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>ID</th><th>User</th><th>Contact</th><th>Role</th>
          <th>Status</th><th>Registered</th><th>Account controls</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $u): ?>
        <tr>
          <td><?= (int) $u['id'] ?></td>
          <td>
            <strong><?= h($u['name']) ?></strong><br>
            <span class="muted">User #<?= (int) $u['id'] ?></span>
          </td>
          <td>
            <?= h($u['email']) ?><br>
            <span class="muted"><?= h($u['phone_number'] ?? '') ?></span>
          </td>
          <td><span class="badge <?= h($u['role']) ?>"><?= h($u['role']) ?></span></td>
          <td><span class="badge <?= h($u['account_status']) ?>"><?= h($u['account_status']) ?></span></td>
          <td><?= h($u['created_at'] ?? '') ?></td>
          <td class="actions">
            <?php if ((int) $u['id'] === $adminId): ?>
              <span class="muted">Your account</span>
            <?php else: ?>
              <?php if ($u['account_status'] === 'active'): ?>
                <form method="post" onsubmit="return confirm('Freeze this account?');">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>">
                  <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                  <input type="hidden" name="action" value="freeze">
                  <button class="suspend">Freeze</button>
                </form>
              <?php else: ?>
                <form method="post" onsubmit="return confirm('Reactivate this account?');">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>">
                  <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                  <input type="hidden" name="action" value="<?= $u['account_status'] === 'banned' ? 'unban' : 'unfreeze' ?>">
                  <button class="activate">Reactivate</button>
                </form>
              <?php endif; ?>
              <?php if ($u['account_status'] !== 'banned'): ?>
                <form method="post" onsubmit="return confirm('Permanently ban sign-in for this account until an admin unbans it?');">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>">
                  <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                  <input type="hidden" name="action" value="ban">
                  <button class="suspend">Ban</button>
                </form>
              <?php endif; ?>

              <?php if ($u['role'] === 'admin'): ?>
                <form method="post" onsubmit="return confirm('Remove administrator privileges?');">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>">
                  <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                  <input type="hidden" name="action" value="demote">
                  <button class="demote">Make user</button>
                </form>
              <?php else: ?>
                <form method="post" onsubmit="return confirm('Promote this user to administrator?');">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>">
                  <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                  <input type="hidden" name="action" value="promote">
                  <button class="promote">Make admin</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>

      <?php if (!$users): ?>
        <tr><td colspan="7">No users match your search.</td></tr>
      <?php endif; ?>
      </tbody>
    </table>
    </div>
    <p class="muted">Showing up to 200 accounts. Account deletion is intentionally not included. Freezing or banning blocks access; reactivation restores it.</p>
  </section>
</main>
</body>
</html>
```