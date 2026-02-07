-- Add row_span column to tiles table for resizable grid system
ALTER TABLE tiles ADD COLUMN IF NOT EXISTS row_span TINYINT UNSIGNED DEFAULT 1 AFTER column_span;

-- Update existing tiles to have default row_span
UPDATE tiles SET row_span = 1 WHERE row_span IS NULL OR row_span = 0;
