CREATE TABLE IF NOT EXISTS scheduled_tasks (
 id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
 name VARCHAR(160) NOT NULL,
 task_type VARCHAR(40) NOT NULL CHECK(task_type IN ('webhook_queue','notification_queue','service_expiry','audit_cleanup')),
 interval_minutes INTEGER NOT NULL CHECK(interval_minutes BETWEEN 1 AND 525600),
 config JSONB NOT NULL DEFAULT '{}'::jsonb,
 is_active SMALLINT NOT NULL DEFAULT 1,
 max_attempts SMALLINT NOT NULL DEFAULT 3 CHECK(max_attempts BETWEEN 1 AND 10),
 next_run_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_run_at TIMESTAMP NULL,
 last_status VARCHAR(20) NULL,
 locked_at TIMESTAMP NULL,
 lock_token VARCHAR(64) NULL,
 created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_scheduled_tasks_due ON scheduled_tasks(is_active,next_run_at);
CREATE TABLE IF NOT EXISTS scheduled_task_runs (
 id BIGINT PRIMARY KEY GENERATED ALWAYS AS IDENTITY,
 task_id BIGINT NOT NULL REFERENCES scheduled_tasks(id) ON DELETE CASCADE,
 run_token VARCHAR(64) NOT NULL UNIQUE,
 status VARCHAR(20) NOT NULL CHECK(status IN ('running','succeeded','failed','skipped')),
 attempt SMALLINT NOT NULL DEFAULT 1,
 message TEXT,
 started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 finished_at TIMESTAMP NULL,
 duration_ms INTEGER NULL
);
CREATE INDEX IF NOT EXISTS idx_task_runs_task ON scheduled_task_runs(task_id,started_at DESC);
INSERT INTO schema_migrations(version) VALUES('1.3.0') ON CONFLICT(version) DO NOTHING;
