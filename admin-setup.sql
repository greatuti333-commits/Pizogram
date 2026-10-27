USE chatwave;

-- Run upgrade.sql first so users.role, users.account_status and admin_actions exist.
-- Replace the email below with the email address of your own registered account.
-- Run this statement once in phpMyAdmin's SQL tab:
UPDATE users SET role = 'admin' WHERE email = 'greatuti333@gmail.com';

-- Verify the account was promoted:
SELECT id, name, email, role, account_status FROM users WHERE email = 'greatuti333@gmail.com';
