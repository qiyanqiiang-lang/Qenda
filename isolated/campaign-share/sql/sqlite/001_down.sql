-- DESTRUCTIVE. Drops isolated campaign-share tables and every key stored in them.
-- Production rollback is scripts/rollback.php, which only writes force-off and does not run this file.
-- Do not run against production.

DROP INDEX IF EXISTS campaign_share_claims_promoter;
DROP INDEX IF EXISTS campaign_share_sessions_promoter;
DROP TABLE IF EXISTS campaign_share_claims;
DROP TABLE IF EXISTS campaign_share_keys;
DROP TABLE IF EXISTS campaign_share_challenges;
DROP TABLE IF EXISTS campaign_share_sessions;
DROP TABLE IF EXISTS campaign_share_csrf;
DROP TABLE IF EXISTS campaign_share_rate_limits;
DROP TABLE IF EXISTS campaign_share_promoters;
DROP TABLE IF EXISTS campaign_share_periods;
