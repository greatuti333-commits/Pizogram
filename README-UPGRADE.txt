CHATWAVE PLUS UPGRADE — INSTALLATION
====================================
This package extends the existing ChatWave PHP/MySQL project. Keep a backup of your current folder and database first.

1. BACK UP
- Copy C:\rrr\htdocs\ChatWave to ChatWave-backup.
- In phpMyAdmin, select database chatwave > Export > Quick > SQL > Export.

2. COPY FILES
- Extract this ZIP.
- Copy the contents of the included ChatWave folder over your current C:\rrr\htdocs\ChatWave folder, keeping your config.php database settings.
- If you use C:\xampp\htdocs\ChatWave instead, copy there and use that same folder consistently. Do not mix the two installations.

3. DATABASE UPGRADE
- Open http://localhost/phpmyadmin/ (or your local phpMyAdmin URL).
- Select the chatwave database, open Import, choose upgrade.sql, and click Import/Go.
- If phpMyAdmin reports a syntax error for ADD COLUMN IF NOT EXISTS, your MySQL/MariaDB build may not support it. Update MariaDB, or add the listed columns manually one by one after checking whether each exists.
- Do not re-import database.sql over a live database unless you know what it changes.

4. MAKE YOUR ACCOUNT AN ADMIN
- Create an ordinary account first and sign in once.
- In phpMyAdmin > chatwave > SQL, run this query with your actual account email:
  UPDATE users SET role='admin' WHERE email='YOUR-REGISTERED-EMAIL@example.com';
- Replace the example email. Never make every account an admin.
- Sign out and sign in again, then open Settings or the Admin button. The Admin dashboard is server-side protected by role checks.

FEATURES INCLUDED IN THIS UPGRADE
- Forgot-account sign-in using a six-digit email verification code, with 10-minute expiry, hashed code storage, attempt limits and request throttling. Successful verification signs the user in.
- Phone-number recovery UI is present, but sending SMS is NOT active until you connect a real SMS provider. The app intentionally does not pretend to send SMS.
- Existing one-to-one messaging, photo/video/file uploads and WebRTC voice/video call starter are retained.
- Voice-note recording and sending in supported browsers.
- Group creation, selecting existing members, group text chat, and group membership tables.
- Status uploads (text/photo/video), 24-hour expiry, likes, reshare copies and media download links.
- Settings for public username, description, read receipts, status visibility, online visibility and away message. Profile picture upload and logout remain in the existing profile UI.
- AI assistant endpoint and interface. Set CHATWAVE_AI_API_KEY on the web server to enable it; optionally set CHATWAVE_AI_MODEL. The API key must stay on the server, never in JavaScript.
- Admin dashboard to freeze/unfreeze and ban/unban accounts. The standalone dashboard uses the same active/frozen/banned statuses as the feature API.

IMPORTANT CONFIGURATION / LIMITATIONS
- Email recovery uses PHP mail(). Many local Windows/XAMPP setups do not have mail delivery configured by default. Configure SMTP/PHP mail delivery and set CHATWAVE_MAIL_FROM before testing. Without that, email codes cannot be delivered.
- SMS delivery needs a real provider and credentials; it is deliberately reported as not configured until integrated.
- AI requires a valid server-side API key, network access, and billing/limits from your chosen AI provider. The UI does not bypass provider requirements.
- Browser voice recording and WebRTC calls require microphone/camera permission. Use localhost for local testing; public deployment requires HTTPS. Some networks need a TURN server. The current calling feature uses database polling and is a starter, not a carrier-grade calling service.
- Group messages load when the group is opened and after sending; they are not WebSocket push and do not auto-refresh continuously. For production real-time scale, add WebSockets/queueing and robust delivery/read states.
- Status download is available only for media status items. Respect users' privacy and consent before sharing or downloading their media.
- Do not expose the local XAMPP development server directly to the public internet without hardening, HTTPS, rate limiting, SMTP/SMS provider setup, secure DB credentials, backups, and security review.

TROUBLESHOOTING
- Always open ChatWave through http://localhost/ChatWave/ (or the exact local virtual-host path), not by double-clicking index.php.
- If you see an unknown table/column error, verify that upgrade.sql was imported into the same chatwave database used by config.php.
- If you see “Security token expired”, refresh with Ctrl+F5 and ensure the page and API are coming from the same installation folder and browser session.
- PHP error details are in the Apache/PHP error log. Keep PHP display_errors off on any public site.


ADMIN ACCESS TROUBLESHOOTING
----------------------------
- First import upgrade.sql into the chatwave database configured in config.php. It adds the role and account_status columns used by the dashboard.
- In phpMyAdmin, run: SELECT id,email,role,account_status FROM users WHERE email='your-email@example.com';
- If it is your account, run: UPDATE users SET role='admin', account_status='active' WHERE email='your-email@example.com';
- Sign out of ChatWave, sign back in with that same email, then visit http://localhost/ChatWave/admin.php.
- If admin.php says access denied, make sure the browser is using the same ChatWave folder/database and that the signed-in email is the account shown as admin.
- This package intentionally does not include files from uploads/ to avoid bundling existing users' images or documents. The uploads folder will be created as needed.
