-- Add theme preferences columns to users table
ALTER TABLE users ADD COLUMN IF NOT EXISTS theme_primary VARCHAR(7) DEFAULT '#0ea5e9' AFTER updated_at;
ALTER TABLE users ADD COLUMN IF NOT EXISTS theme_secondary VARCHAR(7) DEFAULT '#6366f1' AFTER theme_primary;
ALTER TABLE users ADD COLUMN IF NOT EXISTS theme_background VARCHAR(7) DEFAULT '#f3f4f6' AFTER theme_secondary;
ALTER TABLE users ADD COLUMN IF NOT EXISTS theme_font VARCHAR(50) DEFAULT 'system' AFTER theme_background;
