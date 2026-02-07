<?php
/**
 * Notes API Endpoint
 *
 * Handles saving notes to tiles and saving notes to the list.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

// Initialize session
Session::init();

// Require authentication
if (!Auth::check()) {
    jsonError('Unauthorized', 401);
}

// Require AJAX request
if (!isAjax()) {
    jsonError('Invalid request', 400);
}

// Verify CSRF
if (!Auth::verifyCsrf()) {
    jsonError('Invalid security token', 403);
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? 'save';
$userId = Auth::id();

// Ensure notes table exists
try {
    $tableExists = Database::queryOne("SHOW TABLES LIKE 'notes'");
    if (empty($tableExists)) {
        // Check if foreign key constraint is supported
        $fkCheck = Database::queryOne("SELECT @@foreign_key_checks");
        $fkEnabled = ($fkCheck['@@foreign_key_checks'] ?? 1) == 1;
        
        if ($fkEnabled) {
            Database::execute("
                CREATE TABLE notes (
                    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                    user_id INT UNSIGNED NOT NULL,
                    content TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX idx_user_created (user_id, created_at DESC)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } else {
            Database::execute("
                CREATE TABLE notes (
                    id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                    user_id INT UNSIGNED NOT NULL,
                    content TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_user_created (user_id, created_at DESC)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
} catch (Exception $e) {
    error_log('Notes table migration: ' . $e->getMessage());
}

switch ($action) {
    case 'save_to_list':
        // Save current note to the list and clear the tile
        $tileId = (int)($input['tile_id'] ?? 0);
        $notes = trim($input['notes'] ?? '');

        if ($tileId <= 0) {
            jsonError('Invalid tile ID', 400);
        }

        if (empty($notes)) {
            jsonError('Note is empty', 400);
        }

        try {
            // Verify tile belongs to user and get current settings
            $tile = Database::queryOne(
                'SELECT settings FROM tiles WHERE id = ? AND user_id = ?',
                [$tileId, $userId]
            );

            if (!$tile) {
                jsonError('Tile not found', 404);
            }

            $settings = json_decode($tile['settings'] ?? '{}', true);
            if (!is_array($settings)) {
                $settings = [];
            }

            $currentNoteId = isset($settings['current_note_id']) ? (int)$settings['current_note_id'] : null;
            $noteId = null;

            // Check if we're updating an existing note or creating a new one
            if ($currentNoteId && $currentNoteId > 0) {
                // Verify the note still exists and belongs to the user
                $existingNote = Database::queryOne(
                    'SELECT id FROM notes WHERE id = ? AND user_id = ?',
                    [$currentNoteId, $userId]
                );

                if ($existingNote) {
                    // Update existing note
                    Database::execute(
                        'UPDATE notes SET content = ?, updated_at = NOW() WHERE id = ? AND user_id = ?',
                        [$notes, $currentNoteId, $userId]
                    );
                    $noteId = $currentNoteId;
                } else {
                    // Note was deleted, create a new one
                    Database::execute(
                        'INSERT INTO notes (user_id, content) VALUES (?, ?)',
                        [$userId, $notes]
                    );
                    $noteId = Database::lastInsertId();
                    $settings['current_note_id'] = $noteId;
                }
            } else {
                // Create new note (blank tile initially)
                Database::execute(
                    'INSERT INTO notes (user_id, content) VALUES (?, ?)',
                    [$userId, $notes]
                );
                $noteId = Database::lastInsertId();
                $settings['current_note_id'] = $noteId;
            }

            // Clear the tile's notes content
            // Keep current_note_id so if user types again, it continues updating the same note
            // Only clear current_note_id if user explicitly wants to start fresh (future enhancement)
            $settings['notes'] = '';
            
            Database::execute(
                'UPDATE tiles SET settings = ?, updated_at = NOW() WHERE id = ? AND user_id = ?',
                [json_encode($settings), $tileId, $userId]
            );

            jsonResponse([
                'success' => true,
                'message' => $currentNoteId ? 'Note updated' : 'Note saved to list',
                'note_id' => (int)$noteId,
                'updated' => $currentNoteId ? true : false
            ]);
        } catch (Exception $e) {
            logMessage('Save note to list error: ' . $e->getMessage(), 'error');
            jsonError('Failed to save note: ' . $e->getMessage(), 500);
        }
        break;

    case 'delete_note':
        // Delete a note from the saved list
        $noteId = (int)($input['note_id'] ?? 0);

        if ($noteId <= 0) {
            jsonError('Invalid note ID', 400);
        }

        try {
            // Verify note belongs to user and delete
            $deleted = Database::execute(
                'DELETE FROM notes WHERE id = ? AND user_id = ?',
                [$noteId, $userId]
            );

            if ($deleted === 0) {
                jsonError('Note not found', 404);
            }

            // Clear current_note_id from any notes tile that was editing this note
            $tiles = Database::query(
                'SELECT id, settings FROM tiles WHERE user_id = ? AND tile_type = ?',
                [$userId, 'notes']
            );
            foreach ($tiles as $tile) {
                $settings = json_decode($tile['settings'] ?? '{}', true);
                if (!is_array($settings)) {
                    continue;
                }
                $currentNoteId = isset($settings['current_note_id']) ? (int)$settings['current_note_id'] : 0;
                if ($currentNoteId === $noteId) {
                    $settings['current_note_id'] = null;
                    $settings['notes'] = '';
                    Database::execute(
                        'UPDATE tiles SET settings = ?, updated_at = NOW() WHERE id = ? AND user_id = ?',
                        [json_encode($settings), $tile['id'], $userId]
                    );
                }
            }

            jsonResponse([
                'success' => true,
                'message' => 'Note deleted',
                'deleted_id' => $noteId
            ]);
        } catch (Exception $e) {
            logMessage('Delete note error: ' . $e->getMessage(), 'error');
            jsonError('Failed to delete note: ' . $e->getMessage(), 500);
        }
        break;

    case 'load_note':
        // Load a specific note into the editing tile
        $noteId = (int)($input['note_id'] ?? 0);
        $tileId = (int)($input['tile_id'] ?? 0);

        if ($noteId <= 0 || $tileId <= 0) {
            jsonError('Invalid note or tile ID', 400);
        }

        try {
            // Verify note belongs to user
            $note = Database::queryOne(
                'SELECT content FROM notes WHERE id = ? AND user_id = ?',
                [$noteId, $userId]
            );

            if (!$note) {
                jsonError('Note not found', 404);
            }

            // Verify tile belongs to user
            $tile = Database::queryOne(
                'SELECT id FROM tiles WHERE id = ? AND user_id = ?',
                [$tileId, $userId]
            );

            if (!$tile) {
                jsonError('Tile not found', 404);
            }

            // Load note into tile
            // Get current settings or create empty object
            $tile = Database::queryOne(
                'SELECT settings FROM tiles WHERE id = ? AND user_id = ?',
                [$tileId, $userId]
            );
            
            $settings = json_decode($tile['settings'] ?? '{}', true);
            if (!is_array($settings)) {
                $settings = [];
            }
            $settings['notes'] = $note['content'];
            // Store the note ID so we can update it later instead of creating a new one
            $settings['current_note_id'] = $noteId;
            
            Database::execute(
                'UPDATE tiles SET settings = ?, updated_at = NOW() WHERE id = ? AND user_id = ?',
                [json_encode($settings), $tileId, $userId]
            );

            jsonResponse([
                'success' => true,
                'notes' => $note['content'],
                'current_note_id' => $noteId
            ]);
        } catch (Exception $e) {
            logMessage('Load note error: ' . $e->getMessage(), 'error');
            jsonError('Failed to load note: ' . $e->getMessage(), 500);
        }
        break;

    case 'save':
    default:
        // Original save functionality - save notes to tile (auto-save)
        // This preserves current_note_id so we know which note is being edited
        $tileId = (int)($input['tile_id'] ?? 0);
        $notes = trim($input['notes'] ?? '');

        if ($tileId <= 0) {
            jsonError('Invalid tile ID', 400);
        }

        try {
            // Verify tile belongs to user
            $tile = Database::queryOne(
                'SELECT settings FROM tiles WHERE id = ? AND user_id = ?',
                [$tileId, $userId]
            );

            if (!$tile) {
                jsonError('Tile not found', 404);
            }

            // Get existing settings or create new
            $settings = json_decode($tile['settings'] ?? '{}', true);
            if (!is_array($settings)) {
                $settings = [];
            }

            // Update notes in settings
            // Preserve current_note_id if it exists (don't clear it on auto-save)
            $settings['notes'] = $notes;
            // Note: current_note_id is preserved automatically since we're not clearing it

            // Save to database
            Database::execute(
                'UPDATE tiles SET settings = ?, updated_at = NOW() WHERE id = ? AND user_id = ?',
                [json_encode($settings), $tileId, $userId]
            );

            jsonResponse([
                'success' => true,
                'message' => 'Notes saved',
                'saved_at' => date('Y-m-d H:i:s'),
                'current_note_id' => $settings['current_note_id'] ?? null
            ]);
        } catch (Exception $e) {
            logMessage('Notes save error: ' . $e->getMessage(), 'error');
            jsonError('Failed to save notes', 500);
        }
        break;
}
