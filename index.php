<?php
require __DIR__ . '/config.php';
$user = current_user();
$csrf = htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8');
function e($value) { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Pizogram — Messages</title>
  <meta name="theme-color" content="#075e54">
  <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<?php if (!$user): ?>
  <main class="auth-shell">
    <section class="brand-panel">
      <div class="brand-mark">✦</div>
      <h1>Pizogram</h1>
      <p>Simple, private conversations with the people who matter.</p>
      <div class="bubble-art"><span></span><span></span><span></span></div>
    </section>
    <section class="auth-panel">
      <div class="auth-card">
        <div class="tabs">
          <button class="tab active" data-mode="login">Sign in</button>
          <button class="tab" data-mode="register">Create account</button>
        </div>
        <h2 id="auth-title">Welcome back</h2>
        <p class="muted" id="auth-subtitle">Sign in to continue your conversations.</p>
        <form id="auth-form">
          <label id="name-wrap" class="hidden">Display name
            <input name="name" maxlength="60" autocomplete="name" placeholder="Your name">
          </label>
          <label>Email address
            <input name="email" type="email" maxlength="190" required autocomplete="email" placeholder="you@example.com">
          </label>
          <label id="phone-wrap" class="hidden">Phone number (include country code, e.g. +2348012345678)
            <input name="phone" type="tel" maxlength="30" autocomplete="tel" placeholder="+2348012345678">
          </label>
          <label>Password
            <input name="password" type="password" minlength="8" required autocomplete="current-password" placeholder="At least 8 characters">
          </label>
          <button class="primary-btn" type="submit" id="auth-submit">Sign in</button>
          <p class="form-message" id="auth-message" role="status"></p>
        </form>
        <small>Use a unique password. This demo runs on your own server.</small>
      </div>
    </section>
  </main>
<?php else: ?>
  <?php $phoneStmt = db()->prepare('SELECT phone_number FROM users WHERE id=?'); $phoneStmt->execute([(int)$user['id']]); $myPhone = (string)($phoneStmt->fetchColumn() ?: ''); ?>
  <div class="app-shell" id="app" data-csrf="<?= $csrf ?>" data-user-id="<?= (int)$user['id'] ?>" data-phone="<?= e($myPhone) ?>">
    <aside class="sidebar">
      <header class="sidebar-header">
        <div class="profile-mini"><span><?= e(mb_strtoupper(mb_substr($user['name'], 0, 1))) ?></span></div>
        <div class="me-info"><strong><?= e($user['name']) ?></strong><small>Signed in</small></div>
        <button class="icon-btn" id="profile-open" title="Edit profile" aria-label="Edit profile">⚙</button>
        <form action="logout.php" method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><button class="icon-btn" title="Sign out" aria-label="Sign out">↪</button></form>
      </header>
      <div class="search-wrap"><span>⌕</span><input id="search" type="search" placeholder="Search name, email, or phone number"></div>
      <div class="side-label">MESSAGES <span id="contact-count">0</span></div>
      <div id="contact-list" class="contact-list">
        <div class="empty-state">Loading conversations…</div>
      </div>
      <footer class="sidebar-footer">ChatWave <span>•</span> Your own messaging space</footer>
    </aside>
    <main class="chat-panel">
      <section id="welcome" class="welcome">
        <div class="welcome-icon"></div>
        <h1>ChatWave Web</h1>
        <p>Select a conversation or search for someone to start chatting.</p>
        <div class="welcome-note">Your messages are stored in your ChatWave database.</div>
      </section>
      <section id="conversation" class="conversation hidden">
        <header class="chat-header">
          <button id="back-btn" class="icon-btn back-btn" aria-label="Back to chats">‹</button>
          <div id="chat-avatar" class="avatar">?</div>
          <div class="chat-heading"><strong id="chat-name">Contact</strong><small id="chat-status">ChatWave member</small></div>
          <div class="chat-actions"><button id="voice-call" class="call-btn" title="Voice call">☎</button><button id="video-call" class="call-btn" title="Video call">▣</button></div>
        </header>
        <div id="messages" class="messages"><div class="empty-state">Loading messages…</div></div>
        <form id="message-form" class="composer">
          <label class="attach-btn" title="Upload a picture or video">📷<input id="media-input" type="file" accept="image/*,video/mp4,video/webm" hidden></label>
          <label class="attach-btn document-attach" title="Attach a document">＋<input id="file-input" type="file" accept="application/pdf,text/plain,.zip,.docx" hidden></label>
          <input id="message-input" maxlength="4000" autocomplete="off" placeholder="Type a message" aria-label="Message">
          <button type="submit" aria-label="Send message">➤</button>
        </form>
      </section>
    </main>
  </div>
  <div id="profile-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="profile-title">
    <form id="profile-form" class="modal-card">
      <button type="button" id="profile-close" class="modal-close" aria-label="Close">×</button>
      <h2 id="profile-title">Your profile</h2>
      <label>Display name<input name="name" maxlength="60" required minlength="2"></label>
      <label>Phone number<input name="phone" type="tel" maxlength="30" required placeholder="+2348012345678"></label>
      <label>Profile image (optional)<input name="avatar" type="file" accept="image/png,image/jpeg,image/webp"></label>
      <button class="primary-btn" type="submit">Save profile</button>
      <p id="profile-message" class="form-message"></p>
    </form>
  </div>
  <div id="call-modal" class="modal hidden" role="dialog" aria-modal="true" aria-labelledby="call-title">
    <div class="modal-card call-card">
      <button type="button" id="call-close" class="modal-close" aria-label="Close">×</button>
      <h2 id="call-title">Calling…</h2>
      <p id="call-status">Connecting peer-to-peer media.</p>
      <div id="incoming-call-actions" class="call-buttons hidden">
        <button id="call-accept" type="button">Accept call</button>
        <button id="call-decline" type="button" class="danger-btn">Decline</button>
      </div>
      <video id="remote-video" autoplay playsinline></video>
      <video id="local-video" autoplay muted playsinline></video>
      <div id="active-call-actions" class="call-buttons"><button id="call-mute" type="button">Mute mic</button><button id="call-hangup" type="button" class="danger-btn">End call</button></div>
      <small>Calls require camera/microphone permission and HTTPS or localhost. Network/firewall restrictions may prevent connection. A TURN relay may be needed on some networks.</small>
    </div>
  </div>
  <script src="assets/app.js"></script>
<?php endif; ?>
<script>
<?php if (!$user): ?>
window.CHATWAVE_CSRF = <?= json_encode($csrf) ?>;
<?php endif; ?>
</script>
<script src="assets/auth.js"></script>
</body>
</html>
