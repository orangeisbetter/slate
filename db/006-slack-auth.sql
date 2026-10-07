-- 006: sign in with Slack.
-- Slack OAuth lets users sign in without a password. The link is by Slack user id (slack_id)
-- so the same account can be associated once. Email is still the canonical identity when
-- present, and a Slack sign-in that returns an email matching an existing account can be
-- linked to it rather than creating a duplicate.
ALTER TABLE users ADD COLUMN slack_id TEXT;
CREATE UNIQUE INDEX users_slack_id ON users(slack_id);
