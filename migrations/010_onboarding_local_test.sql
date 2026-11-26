ALTER TABLE onboarding_simulations
    ADD COLUMN mode TEXT NOT NULL DEFAULT 'simulation',
    ADD COLUMN started_at TIMESTAMPTZ,
    ADD COLUMN outcomes JSONB NOT NULL DEFAULT '[]';
