-- DESTRUCTIVE review artifact. Drops isolated campaign_share_* tables and the keys in them.
-- scripts/rollback.php does not execute this file.
-- Do not run against production. Already issued keys would be deleted.

DROP TABLE IF EXISTS campaign_share_claims;
DROP TABLE IF EXISTS campaign_share_keys;
DROP TABLE IF EXISTS campaign_share_challenges;
DROP TABLE IF EXISTS campaign_share_sessions;
DROP TABLE IF EXISTS campaign_share_csrf;
DROP TABLE IF EXISTS campaign_share_rate_limits;
DROP TABLE IF EXISTS campaign_share_promoters;
DROP TABLE IF EXISTS campaign_share_periods;
