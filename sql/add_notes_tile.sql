-- Add a Quick Notes tile
-- 
-- Option 1: Add for a specific user (replace YOUR_USER_ID with your actual user ID)
-- First, find your user ID: SELECT id, username, email FROM users;
--
-- INSERT INTO tiles (user_id, tile_type, title, position, column_span, row_span, is_enabled)
-- SELECT 
--     YOUR_USER_ID,
--     'notes',
--     'Quick Notes',
--     COALESCE(MAX(position), 0) + 1,
--     1,
--     1,
--     TRUE
-- FROM tiles
-- WHERE user_id = YOUR_USER_ID AND is_enabled = TRUE;

-- Option 2: Add for all users (adds notes tile to every user who doesn't have one)
INSERT INTO tiles (user_id, tile_type, title, position, column_span, row_span, is_enabled)
SELECT 
    u.id as user_id,
    'notes' as tile_type,
    'Quick Notes' as title,
    COALESCE(MAX(t.position), 0) + 1 as position,
    1 as column_span,
    1 as row_span,
    TRUE as is_enabled
FROM users u
LEFT JOIN tiles t ON u.id = t.user_id AND t.is_enabled = TRUE
WHERE NOT EXISTS (
    SELECT 1 FROM tiles t2 
    WHERE t2.user_id = u.id 
    AND t2.tile_type = 'notes' 
    AND t2.is_enabled = TRUE
)
GROUP BY u.id;
