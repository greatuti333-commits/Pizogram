CHATWAVE PLUS — ENHANCED WHATSAPP-STYLE MESSAGING STARTER

FEATURES INCLUDED
A. WhatsApp-inspired responsive UI and mobile layout.
B. Phone numbers on account registration, searchable contacts, and phone numbers displayed in contact/chat headers.
C. Dedicated photo/video upload button with inline image and playable video previews.
D. Fast message refresh by HTTP polling (about every 1.8 seconds).
E. WebRTC voice/video call interface and database-based signaling.
F. Image, video, and document attachments (15 MB limit).
G. Profile name/photo/phone editing, online status, sent/read indicators.

INSTALL ON WINDOWS
1. Install XAMPP from https://www.apachefriends.org/
2. Extract this folder to C:\xampp\htdocs\ChatWave (or your XAMPP htdocs folder).
3. Start Apache and MySQL in XAMPP Control Panel.
4. Open http://localhost/phpmyadmin
5. Select Import and import database.sql.
   For an existing ChatWave database, use upgrade_phone_numbers.sql once if the phone_number column is missing. If you use database.sql, be aware that some older MySQL versions do not support ADD COLUMN IF NOT EXISTS; add missing columns manually or use a fresh database.
6. Check config.php DB_PASS. XAMPP's default root password is usually blank.
7. Open http://localhost/ChatWave/
8. Create two accounts in separate browsers (enter phone numbers with country codes such as +2348012345678), search by name or phone number, and test chat. Use the camera button to send a picture or video. Existing accounts can add a phone number from Settings/Profile.

IMPORTANT LIMITATIONS
- B is polling, not WebSockets. True WebSocket delivery requires a separately deployed persistent
  Node.js/WebSocket service; this package intentionally does not claim that polling is WebSockets.
- WebRTC calls are a starter implementation using a public STUN server and PHP/database signaling.
  The recipient-side incoming-call/accept flow is not yet a complete polished call UX; calls may fail
  behind NATs/firewalls without a TURN server. Do not rely on this for emergency communications.
- Calls require HTTPS or localhost, camera/microphone permission, and a modern browser.
- Attachments are limited to selected MIME types and 15 MB, but public hosting should use malware
  scanning, private storage, authenticated download routes, quotas, and rate limits.
- This is not the official WhatsApp product and does not provide end-to-end encryption.
- Do not expose this demo publicly until you add HTTPS, secure cookie configuration, rate limiting,
  email verification, account recovery, abuse reporting, retention controls, backups, and logging.
- For a public launch, configure PHP upload_max_filesize and post_max_size to at least 16M.
- Make sure uploads/ cannot execute scripts; .htaccess does this on Apache, but verify your host honors it.

ONLINE HOSTING CHECKLIST
1. Buy a domain and choose PHP 8.2+ hosting with MySQL/MariaDB and HTTPS.
2. Upload the project files to the host's web root.
3. Create a database and database user in your hosting panel; import database.sql.
4. Update DB_HOST, DB_NAME, DB_USER, DB_PASS in config.php. Never leave production credentials public.
5. Enable HTTPS and set secure session cookie settings in config.php.
6. Ensure uploads/ is writable by PHP and script execution is disabled.
7. Test signup, login, search, messaging, attachments, mobile layout, and permissions.
8. Back up the database and uploaded files regularly.

TRUE WEBSOCKETS
To convert the current polling implementation to WebSockets, deploy a persistent Node.js service
with authentication tied to the PHP session or short-lived signed tokens, TLS (wss://), origin checks,
message authorization, rate limiting, reconnect handling, and database persistence. Do not expose an
unauthenticated broadcast socket to the public internet.


CHATWAVE PRO UPGRADE NOTES
This version improves the incoming WebRTC call flow: recent incoming ring events are discovered,
the recipient can accept or decline, the recipient processes the offer and returns an answer, and
the caller/recipient exchange ICE candidates through the database-backed signaling endpoint.

TESTING CALLS
1. Open the app in two separate browsers/devices and sign in as two different users.
2. Search and open each other's chats.
3. Allow microphone/camera permissions.
4. Start a voice or video call from one side. The other side should see an incoming call prompt.
5. Accept it and check connection status. For many public networks, a TURN server is needed.
6. Calls use a public STUN server by default; do not assume all NAT/firewall combinations will work.

KNOWN LIMITATIONS
- Message updates still use periodic HTTP polling; this build does not claim to implement WebSockets.
- Calls are a starter implementation and need testing on your target hosting/network. The caller must
  remain signed in and the call recipient must be online in the app to see the incoming prompt.
- For public hosting, use HTTPS, configure a TURN server, rate-limit call signaling, clean up expired
  call_signals, and implement authenticated private attachment downloads.
- Do not describe this as WhatsApp or claim end-to-end encryption.

PHONE NUMBERS AND MEDIA UPLOAD
- New accounts must provide a phone number with 7–15 digits; a leading + is allowed. Each number must be unique.
- Contact search matches display name, email, and phone number. A member's number appears in the contact list and chat header.
- Use the camera button in a chat to upload images/videos; images preview inline and videos can be played in the conversation.
- Existing users can add/update their phone number through the profile/settings dialog. If updating an old database, import database.sql or run: ALTER TABLE users ADD COLUMN phone_number VARCHAR(30) NULL UNIQUE;
- Uploads are limited to 15 MB by the PHP endpoint. Adjust upload_max_filesize and post_max_size in php.ini only if you also intentionally raise server-side limits.
