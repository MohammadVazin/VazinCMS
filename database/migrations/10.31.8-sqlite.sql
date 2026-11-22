-- CANDIDATE_REVIEW_ONLY: intended migration 10.31.8.
-- Source SHA256: be8e28db66bd07fd91dd44dfdbfd1ec5b355bbb54888330a5ba27a580fdbcf13
-- Do NOT apply to live database until signed release and DR accepted.
-- PROPOSAL ONLY. Tenant-bound browser admin sessions for candidate VazinCMS.
-- Depends on fully verified phase2+ site schema, active_sessions and domain
-- ownership. No backfill: existing global sessions have NO implicit site grant.
CREATE TABLE IF NOT EXISTS cms_site_admin_sessions (
 id INTEGER PRIMARY KEY AUTOINCREMENT,
 site_id INTEGER NOT NULL REFERENCES cms_sites(id) ON DELETE RESTRICT,
 user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 active_session_id INTEGER NOT NULL UNIQUE REFERENCES active_sessions(id) ON DELETE CASCADE,
 session_hash TEXT NOT NULL UNIQUE,
 bound_host TEXT NOT NULL,
 auth_provider TEXT NOT NULL CHECK(auth_provider IN('password','vazin_id')),
 session_version INTEGER NOT NULL CHECK(session_version>0),
 authenticated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at TEXT NOT NULL,
 revoked_at TEXT,
 FOREIGN KEY(site_id,user_id) REFERENCES cms_site_memberships(site_id,user_id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS idx_cms_site_admin_session_owner
 ON cms_site_admin_sessions(site_id,user_id,revoked_at,expires_at);

-- Revocation triggers also fire when a maintenance connection forgets
-- PRAGMA foreign_keys=ON. A restored membership/domain can never silently
-- re-authorize the old browser session.
CREATE TRIGGER IF NOT EXISTS trg_cms_site_admin_member_delete
 AFTER DELETE ON cms_site_memberships
 BEGIN
 UPDATE cms_site_admin_sessions SET revoked_at=CURRENT_TIMESTAMP
 WHERE site_id=OLD.site_id AND user_id=OLD.user_id AND revoked_at IS NULL;
 END;
CREATE TRIGGER IF NOT EXISTS trg_cms_site_admin_member_role
 AFTER UPDATE OF site_role ON cms_site_memberships
 WHEN OLD.site_role IS NOT NEW.site_role
 BEGIN
 UPDATE cms_site_admin_sessions SET revoked_at=CURRENT_TIMESTAMP
 WHERE site_id=OLD.site_id AND user_id=OLD.user_id AND revoked_at IS NULL;
 END;
CREATE TRIGGER IF NOT EXISTS trg_cms_site_admin_domain_delete
 AFTER DELETE ON cms_site_domains
 BEGIN
 UPDATE cms_site_admin_sessions SET revoked_at=CURRENT_TIMESTAMP
 WHERE site_id=OLD.site_id AND bound_host=lower(OLD.domain) AND revoked_at IS NULL;
 END;
CREATE TRIGGER IF NOT EXISTS trg_cms_site_admin_domain_change
 AFTER UPDATE OF domain,verified_at ON cms_site_domains
 WHEN lower(OLD.domain) IS NOT lower(NEW.domain)
  OR (OLD.verified_at IS NOT NULL AND NEW.verified_at IS NULL)
 BEGIN
 UPDATE cms_site_admin_sessions SET revoked_at=CURRENT_TIMESTAMP
 WHERE site_id=OLD.site_id AND bound_host=lower(OLD.domain) AND revoked_at IS NULL;
 END;
