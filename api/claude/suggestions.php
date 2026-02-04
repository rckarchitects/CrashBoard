<?php
/**
 * Claude AI Suggestions Endpoint - Debug Version
 */

// Output JSON no matter what
header('Content-Type: application/json');

// Start output buffering to prevent partial output
ob_start();

try {
    require_once __DIR__ . '/../../includes/functions.php';
    require_once __DIR__ . '/../../includes/auth.php';

    // Require authentication
    if (!Auth::check()) {
        ob_end_clean();
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // Require AJAX request
    if (!isAjax()) {
        ob_end_clean();
        echo json_encode(['error' => 'Invalid request - not AJAX']);
        exit;
    }

    // Verify CSRF
    if (!Auth::verifyCsrf()) {
        ob_end_clean();
        echo json_encode(['error' => 'Invalid security token']);
        exit;
    }

    $userId = Auth::id();

    // Check cache for suggestions
    $cacheKey = "suggestions_{$userId}";
    $cached = cache($cacheKey);

    if ($cached !== null) {
        ob_end_clean();
        echo json_encode($cached);
        exit;
    }

    // Get data from cache only (don't try to fetch directly - that might be causing the crash)
    $crmData = cache("crm_{$userId}");
    $weatherData = cache("weather_{$userId}");
    $emailData = cache("email_{$userId}");
    $calendarData = cache("calendar_{$userId}");
    $todoData = cache("todo_{$userId}");

    $debugInfo = [
        'crm_connected' => $crmData['connected'] ?? false,
        'weather_configured' => $weatherData['configured'] ?? false,
        'email_connected' => $emailData['connected'] ?? false,
        'calendar_connected' => $calendarData['connected'] ?? false,
        'todo_connected' => $todoData['connected'] ?? false,
    ];

    // Check if any service has data
    $hasData = !empty($crmData['connected']) ||
               !empty($weatherData['configured']) ||
               !empty($emailData['connected']) ||
               !empty($calendarData['connected']) ||
               !empty($todoData['connected']);

    if (!$hasData) {
        ob_end_clean();
        echo json_encode([
            'suggestions' => [],
            'summary' => 'Connect your services in Settings to get personalized suggestions.',
            'hasData' => false,
            'debug' => $debugInfo
        ]);
        exit;
    }

    // Build data message for Claude
    $dashboardData = [
        'email' => $emailData,
        'calendar' => $calendarData,
        'todo' => $todoData,
        'crm' => $crmData,
        'weather' => $weatherData,
        'currentTime' => date('Y-m-d H:i:s'),
        'dayOfWeek' => date('l'),
        'timeOfDay' => getTimeOfDay()
    ];

    $systemPrompt = buildSuggestionsPrompt();
    $userMessage = buildDataMessage($dashboardData);

    // Call Claude API
    $claudeConfig = config('claude');

    if (empty($claudeConfig['api_key'])) {
        ob_end_clean();
        echo json_encode(['error' => 'Claude API key not configured']);
        exit;
    }

    $payload = [
        'model' => $claudeConfig['model'] ?? 'claude-sonnet-4-20250514',
        'max_tokens' => 1024,
        'system' => $systemPrompt,
        'messages' => [
            ['role' => 'user', 'content' => $userMessage]
        ]
    ];

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'x-api-key: ' . $claudeConfig['api_key'],
                'anthropic-version: 2023-06-01'
            ]),
            'content' => json_encode($payload),
            'timeout' => 30,
            'ignore_errors' => true
        ]
    ]);

    $response = @file_get_contents('https://api.anthropic.com/v1/messages', false, $context);

    if (!$response) {
        ob_end_clean();
        echo json_encode(['error' => 'Failed to connect to Claude API']);
        exit;
    }

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        ob_end_clean();
        echo json_encode(['error' => 'Claude API: ' . ($data['error']['message'] ?? 'Unknown error')]);
        exit;
    }

    if (empty($data['content'][0]['text'])) {
        ob_end_clean();
        echo json_encode(['error' => 'Empty response from Claude']);
        exit;
    }

    $responseText = $data['content'][0]['text'];

    // Try to extract JSON
    if (preg_match('/\{[\s\S]*\}/', $responseText, $matches)) {
        $responseText = $matches[0];
    }

    $parsed = json_decode($responseText, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        $parsed = [
            'summary' => 'Unable to parse suggestions.',
            'priorities' => [],
            'suggestions' => []
        ];
    }

    $result = [
        'suggestions' => $parsed['suggestions'] ?? [],
        'summary' => $parsed['summary'] ?? '',
        'priorities' => $parsed['priorities'] ?? [],
        'hasData' => true,
        'generatedAt' => date('c')
    ];

    // Cache for 10 minutes
    cache($cacheKey, fn() => $result, 600);

    ob_end_clean();
    echo json_encode($result);

} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
}
exit;

// Helper functions

function getTimeOfDay(): string
{
    $hour = (int) date('G');
    if ($hour < 12) return 'morning';
    if ($hour < 17) return 'afternoon';
    if ($hour < 21) return 'evening';
    return 'night';
}

function buildSuggestionsPrompt(): string
{
    return <<<'PROMPT'
You are an intelligent personal assistant analyzing a user's dashboard data. Provide actionable suggestions.

Respond with a JSON object containing:
1. "summary": Brief overview (1-2 sentences)
2. "priorities": Array of 1-3 top priorities
3. "suggestions": Array of 3-6 suggestions, each with "text", "type" (urgent/important/followup/planning/wellness), and "source" (email/calendar/crm/tasks/weather/general)

IMPORTANT: Respond ONLY with valid JSON.
PROMPT;
}

function buildDataMessage(array $data): string
{
    $message = "Current time: {$data['currentTime']} ({$data['dayOfWeek']} {$data['timeOfDay']})\n\n";

    $crm = $data['crm'] ?? [];
    if (!empty($crm['connected']) && !empty($crm['actions'])) {
        $message .= "=== CRM ACTIONS ===\n";
        foreach ($crm['actions'] as $action) {
            $overdue = !empty($action['isOverdue']) ? ' [OVERDUE]' : '';
            $message .= "- {$action['contactName']}: {$action['actionText']} (Due: {$action['dueDate']}){$overdue}\n";
        }
        $message .= "\n";
    }

    $weather = $data['weather'] ?? [];
    if (!empty($weather['configured']) && empty($weather['error'])) {
        $message .= "=== WEATHER ===\n";
        $current = $weather['current'] ?? [];
        $message .= "Location: " . ($weather['location'] ?? 'Unknown') . "\n";
        $message .= "Current: " . ($current['temperature'] ?? 'N/A') . ($weather['units'] ?? '°C');
        $message .= ", " . ($current['description'] ?? 'Unknown') . "\n";
    }

    $email = $data['email'] ?? [];
    if (!empty($email['connected'])) {
        $message .= "=== EMAILS ===\n";
        $message .= "Unread count: " . ($email['unreadCount'] ?? 0) . "\n";
    }

    $calendar = $data['calendar'] ?? [];
    if (!empty($calendar['connected'])) {
        $message .= "=== CALENDAR ===\n";
        $message .= count($calendar['events'] ?? []) . " events today\n";
    }

    $todo = $data['todo'] ?? [];
    if (!empty($todo['connected'])) {
        $message .= "=== TASKS ===\n";
        $message .= count($todo['tasks'] ?? []) . " pending tasks\n";
    }

    return $message;
}
