-- Store the Telegram profile (username / first_name) on the user row so the
-- admin "view user info" flow can display it without a live getChatMember call
-- (which fails with "chat not found" for users who never messaged the bot).
ALTER TABLE `users`
    ADD COLUMN `username` varchar(64) COLLATE utf8mb4_bin DEFAULT NULL,
    ADD COLUMN `first_name` varchar(64) COLLATE utf8mb4_bin DEFAULT NULL;
