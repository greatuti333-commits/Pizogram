-- Run this ONCE in phpMyAdmin if you already have a ChatWave database.
-- Do not run it if users.phone_number already exists.
USE chatwave;
ALTER TABLE users ADD COLUMN phone_number VARCHAR(30) NULL UNIQUE;
