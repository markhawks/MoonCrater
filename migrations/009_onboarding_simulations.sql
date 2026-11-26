CREATE TABLE IF NOT EXISTS onboarding_simulations (
    id BIGSERIAL PRIMARY KEY,
    created_by TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    targets JSONB NOT NULL,
    completed_at TIMESTAMPTZ
);
