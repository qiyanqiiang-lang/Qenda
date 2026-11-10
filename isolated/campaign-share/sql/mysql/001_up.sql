-- Review artifact for a future, separately approved database.
-- Not executed by this package. Not safe to run on production from this repository.
-- Creates only new campaign_share_* tables. Does not alter older key tables.

CREATE TABLE campaign_share_periods (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) NOT NULL,
  status VARCHAR(16) NOT NULL,
  max_claims INT UNSIGNED NOT NULL,
  issued_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at BIGINT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_campaign_share_periods_code (code),
  CONSTRAINT chk_campaign_share_periods_status CHECK (status IN ('open', 'closed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaign_share_promoters (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_code VARCHAR(80) NOT NULL,
  display_name VARCHAR(128) NOT NULL,
  eligible TINYINT NOT NULL,
  period_id BIGINT UNSIGNED NOT NULL,
  created_at BIGINT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_campaign_share_promoters_code (public_code),
  KEY idx_campaign_share_promoters_period (period_id),
  CONSTRAINT fk_campaign_share_promoters_period FOREIGN KEY (period_id) REFERENCES campaign_share_periods (id),
  CONSTRAINT chk_campaign_share_promoters_eligible CHECK (eligible IN (0, 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaign_share_keys (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id VARCHAR(64) NOT NULL,
  secret VARCHAR(128) NOT NULL,
  period_id BIGINT UNSIGNED NOT NULL,
  promoter_id BIGINT UNSIGNED NOT NULL,
  bound_phone VARCHAR(32) NOT NULL,
  pool VARCHAR(64) NOT NULL DEFAULT 'campaign_share_isolated',
  created_at BIGINT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_campaign_share_keys_public (public_id),
  UNIQUE KEY uq_campaign_share_keys_secret (secret),
  UNIQUE KEY uq_campaign_share_keys_phone (bound_phone),
  CONSTRAINT fk_campaign_share_keys_period FOREIGN KEY (period_id) REFERENCES campaign_share_periods (id),
  CONSTRAINT fk_campaign_share_keys_promoter FOREIGN KEY (promoter_id) REFERENCES campaign_share_promoters (id),
  CONSTRAINT chk_campaign_share_keys_pool CHECK (pool = 'campaign_share_isolated')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaign_share_claims (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  key_id BIGINT UNSIGNED NOT NULL,
  promoter_id BIGINT UNSIGNED NOT NULL,
  period_id BIGINT UNSIGNED NOT NULL,
  visitor_label VARCHAR(32) NOT NULL,
  status VARCHAR(32) NOT NULL,
  claimed_at BIGINT NOT NULL,
  used_at BIGINT NULL,
  source_code VARCHAR(80) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_campaign_share_claims_key (key_id),
  UNIQUE KEY uq_campaign_share_claims_label (visitor_label),
  KEY idx_campaign_share_claims_promoter (promoter_id),
  CONSTRAINT fk_campaign_share_claims_key FOREIGN KEY (key_id) REFERENCES campaign_share_keys (id),
  CONSTRAINT fk_campaign_share_claims_promoter FOREIGN KEY (promoter_id) REFERENCES campaign_share_promoters (id),
  CONSTRAINT fk_campaign_share_claims_period FOREIGN KEY (period_id) REFERENCES campaign_share_periods (id),
  CONSTRAINT chk_campaign_share_claims_status CHECK (status IN ('claimed_bound', 'used'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaign_share_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash CHAR(64) NOT NULL,
  phone VARCHAR(32) NULL,
  invite_code VARCHAR(80) NULL,
  promoter_id BIGINT UNSIGNED NULL,
  purpose VARCHAR(16) NOT NULL,
  expires_at BIGINT NOT NULL,
  created_at BIGINT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_campaign_share_sessions_token (token_hash),
  KEY idx_campaign_share_sessions_promoter (promoter_id),
  CONSTRAINT chk_campaign_share_sessions_purpose CHECK (purpose IN ('visitor', 'promoter'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaign_share_csrf (
  cookie_hash CHAR(64) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at BIGINT NOT NULL,
  PRIMARY KEY (cookie_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaign_share_challenges (
  id CHAR(32) NOT NULL,
  phone VARCHAR(32) NOT NULL,
  invite_code VARCHAR(80) NOT NULL,
  promoter_id BIGINT UNSIGNED NOT NULL,
  expires_at BIGINT NOT NULL,
  consumed_at BIGINT NULL,
  PRIMARY KEY (id),
  CONSTRAINT fk_campaign_share_challenges_promoter FOREIGN KEY (promoter_id) REFERENCES campaign_share_promoters (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaign_share_rate_limits (
  bucket CHAR(64) NOT NULL,
  window_start BIGINT NOT NULL,
  hits INT UNSIGNED NOT NULL,
  PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
