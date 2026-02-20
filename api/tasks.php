<?php
/**
 * Tasks API – mark a task complete (To Do or Planner)
 *
 * POST body: { "task_id": "...", "source": "todo"|"planner", "list_id": "..." }
 * list_id required when source is "todo".
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

define('CRASHBOARD_LOAD_TILES_FUNCTIONS_ONLY', true);
require_once __DIR__ . '/tiles.php';

Session::init();

if (!Auth::check()) {
    jsonError('Unauthorized', 401);
}

if (!isAjax() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonError('Invalid request', 400);
}

if (!Auth::verifyCsrf()) {
    jsonError('Invalid security token', 403);
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$taskId = trim((string) ($input['task_id'] ?? ''));
$source = trim((string) ($input['source'] ?? ''));
$listId = trim((string) ($input['list_id'] ?? ''));

if ($taskId === '') {
    jsonError('Missing task_id', 400);
}

if ($source !== 'todo' && $source !== 'planner') {
    jsonError('Invalid source; use "todo" or "planner"', 400);
}

if ($source === 'todo' && $listId === '') {
    jsonError('list_id required for To Do tasks', 400);
}

$userId = Auth::id();
$token = getOAuthToken($userId, 'microsoft');

if (!$token) {
    jsonError('Microsoft account not connected', 403);
}

try {
    if ($source === 'todo') {
        callMicrosoftGraphPatch(
            $token,
            "/me/todo/lists/" . $listId . "/tasks/" . $taskId,
            ['status' => 'completed'],
            'v1.0'
        );
    } else {
        // Planner: need ETag for PATCH. GET task first.
        $task = callMicrosoftGraph($token, '/planner/tasks/' . $taskId);
        $etag = $task['@odata.etag'] ?? null;
        if ($etag === null) {
            throw new Exception('Planner task ETag not found');
        }
        callMicrosoftGraphPatch(
            $token,
            '/planner/tasks/' . $taskId,
            ['percentComplete' => 100],
            'v1.0',
            ['If-Match' => $etag]
        );
    }

    cacheClear('todo_' . $userId);
    cacheClear('overdue_tasks_count_' . $userId);
    if ($source === 'planner') {
        cacheClear('planner_overview_v2_' . $userId);
    }

    jsonResponse(['success' => true]);
} catch (Exception $e) {
    logMessage('Task complete error: ' . $e->getMessage(), 'error');
    jsonError('Failed to update task: ' . $e->getMessage(), 500);
}
