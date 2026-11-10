-- Isolated campaign-share tables for the fixture database only.
-- Do not run against production. This file does not alter any pre-existing key table.

CREATE TABLE campaign_share_periods (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE,
  status TEXT NOT NULL CHECK (status IN ('open', 'closed')),
  max_claims INTEGER NOT NULL CHECK (max_claims >= 0),
  issued_count INTEGER NOT NULL DEFAULT 0 CHECK (issued_count >= 0),
  created_at INTEGER NOT NULL
);

CREATE TABLE campaign_share_promoters (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  public_code TEXT NOT NULL UNIQUE,
  display_name TEXT NOT NULL,
  eligible INTEGER NOT NULL CHECK (eligible IN (0, 1)),
  period_id INTEGER NOT NULL,
  created_at INTEGER NOT NULL,
  FOREIGN KEY (period_id) REFERENCES campaign_share_periods(id)
);

CREATE TABLE campaign_share_keys (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  public_id TEXT NOT NULL UNIQUE,
  secret TEXT NOT NULL UNIQUE,
  period_id INTEGER NOT NULL,
  promoter_id INTEGER NOT NULL,
  bound_phone TEXT NOT NULL UNIQUE,
  pool TEXT NOT NULL DEFAULT 'campaign_share_isolated' CHECK (pool = 'campaign_share_isolated'),
  created_at INTEGER NOT NULL,
  FOREIGN KEY (period_id) REFERENCES campaign_share_periods(id),
  FOREIGN KEY (promoter_id) REFERENCES campaign_share_promoters(id)
);

CREATE TABLE campaign_share_claims (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  key_id INTEGER NOT NULL UNIQUE,
  promoter_id INTEGER NOT NULL,
  period_id INTEGER NOT NULL,
  visitor_label TEXT NOT NULL UNIQUE,
  status TEXT NOT NULL CHECK (status IN ('claimed_bound', 'used')),
  claimed_at INTEGER NOT NULL,
  used_at INTEGER,
  source_code TEXT NOT NULL,
  FOREIGN KEY (key_id) REFERENCES campaign_share_keys(id),
  FOREIGN KEY (promoter_id) REFERENCES campaign_share_promoters(id),
  FOREIGN KEY (period_id) REFERENCES campaign_share_periods(id)
);

CREATE TABLE campaign_share_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  token_hash TEXT NOT NULL UNIQUE,
  phone TEXT,
  invite_code TEXT,
  promoter_id INTEGER,
  purpose TEXT NOT NULL CHECK (purpose IN ('visitor', 'promoter')),
  expires_at INTEGER NOT NULL,
  created_at INTEGER NOT NULL
);

CREATE TABLE campaign_share_csrf (
  cookie_hash TEXT PRIMARY KEY,
  token_hash TEXT NOT NULL,
  expires_at INTEGER NOT NULL
);

CREATE TABLE campaign_share_challenges (
  id TEXT PRIMARY KEY,
  phone TEXT NOT NULL,
  invite_code TEXT NOT NULL,
  promoter_id INTEGER NOT NULL,
  expires_at INTEGER NOT NULL,
  consumed_at INTEGER,
  FOREIGN KEY (promoter_id) REFERENCES campaign_share_promoters(id)
);

CREATE TABLE campaign_share_rate_limits (
  bucket TEXT PRIMARY KEY,
  window_start INTEGER NOT NULL,
  hits INTEGER NOT NULL
);

CREATE INDEX campaign_share_claims_promoter ON campaign_share_claims (promoter_id);
CREATE INDEX campaign_share_sessions_promoter ON campaign_share_sessions (promoter_id);
