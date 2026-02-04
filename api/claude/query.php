<?php
/**
 * Claude AI Query Endpoint
 *
 * Handles natural language queries and summarization requests.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

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

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$query = trim($input['query'] ?? '');

if (empty($query)) {
    jsonError('Please enter a question', 400);
}

// Rate limit check (simple implementation)
$rateLimitKey = 'claude_rate_' . Auth::id();
$attempts = (int) ($_SESSION[$rateLimitKey . '_count'] ?? 0);
$lastAttempt = (int) ($_SESSION[$rateLimitKey . '_time'] ?? 0);

if (time() - $lastAttempt < 60) {
    if ($attempts >= 10) {
        jsonError('Rate limit exceeded. Please wait a moment before asking another question.', 429);
    }
    $_SESSION[$rateLimitKey . '_count'] = $attempts + 1;
} else {
    $_SESSION[$rateLimitKey . '_count'] = 1;
}
$_SESSION[$rateLimitKey . '_time'] = time();

// Get dashboard context for the AI
$userId = Auth::id();
$context = gatherDashboardContext($userId);

// Build the prompt
$systemPrompt = <<<PROMPT
You are an AI assistant integrated into a personal dashboard called CrashBoard. You have access to the user's dashboard data including:

- Email inbox (unread emails and recent messages)
- Calendar events for today
- Tasks from Microsoft To Do
- CRM actions from OnePageCRM

Your role is to:
1. Answer questions about the user's data
2. Provide summaries when requested
3. Help prioritize tasks and meetings
4. Offer helpful insights about their schedule and workload

Be concise and helpful. If you don't have access to certain data (because it's not connected), mention that the user can connect it in settings.

Current dashboard context:
{$context}
PROMPT;

try {
    $response = callClaudeAPI($systemPrompt, $query);
    jsonResponse(['response' => $response]);
} catch (Exception $e) {
    logMessage('Claude API error: ' . $e->getMessage(), 'error');
    jsonError('Sorry, I encountered an error processing your request. Please try again.', 500);
}

/**
 * Gather dashboard context for Claude
 */
function gatherDashboardContext(int $userId): string
{
    $context = [];

    // Get email summary
    $emailData = getTileDataForContext($userId, 'email');
    if ($emailData && $emailData['connected']) {
        $unreadCount = $emailData['unreadCount'] ?? 0;
        $context[] = "EMAILS: {$unreadCount} unread emails.";
        if (!empty($emailData['emails'])) {
            $context[] = "Recent unread emails:";
            foreach (array_slice($emailData['emails'], 0, 5) as $email) {
                $context[] = "- From: {$email['from']} | Subject: {$email['subject']}";
            }
        }
    } else {
        $context[] = "EMAILS: Not connected.";
    }

    // Get calendar summary
    $calendarData = getTileDataForContext($userId, 'calendar');
    if ($calendarData && $calendarData['connected']) {
        $eventCount = count($calendarData['events'] ?? []);
        $context[] = "\nCALENDAR: {$eventCount} events today.";
        if (!empty($calendarData['events'])) {
            foreach ($calendarData['events'] as $event) {
                $location = $event['location'] ? " at {$event['location']}" : '';
                $context[] = "- {$event['startTime']}: {$event['subject']}{$location}";
            }
        }
    } else {
        $context[] = "\nCALENDAR: Not connected.";
    }

    // Get tasks summary
    $todoData = getTileDataForContext($userId, 'todo');
    if ($todoData && $todoData['connected']) {
        $taskCount = count($todoData['tasks'] ?? []);
        $context[] = "\nTASKS: {$taskCount} pending tasks.";
        if (!empty($todoData['tasks'])) {
            foreach (array_slice($todoData['tasks'], 0, 5) as $task) {
                $due = $task['dueDate'] ? " (Due: {$task['dueDate']})" : '';
                $priority = $task['importance'] === 'high' ? ' [HIGH]' : '';
                $context[] = "- {$task['title']}{$priority}{$due}";
            }
        }
    } else {
        $context[] = "\nTASKS: Not connected.";
    }

    // Get CRM summary
    $crmData = getTileDataForContext($userId, 'crm');
    if ($crmData && $crmData['connected']) {
        $actionCount = count($crmData['actions'] ?? []);
        $context[] = "\nCRM ACTIONS: {$actionCount} pending actions.";
        if (!empty($crmData['actions'])) {
            foreach (array_slice($crmData['actions'], 0, 5) as $action) {
                $overdue = $action['isOverdue'] ? ' [OVERDUE]' : '';
                $context[] = "- {$action['contactName']}: {$action['actionText']} (Due: {$action['dueDate']}){$overdue}";
            }
        }
    } else {
        $context[] = "\nCRM: Not connected.";
    }

    // Get weather summary
    $weatherData = getTileDataForContext($userId, 'weather');
    if ($weatherData && $weatherData['configured'] && !isset($weatherData['error'])) {
        $current = $weatherData['current'] ?? [];
        $location = $weatherData['location'] ?? 'Unknown';
        $temp = $current['temperature'] ?? 'N/A';
        $units = $weatherData['units'] ?? '°C';
        $description = $current['description'] ?? 'Unknown';
        $context[] = "\nWEATHER ({$location}): {$temp}{$units}, {$description}.";

        // Add forecast summary
        if (!empty($weatherData['forecast'])) {
            $context[] = "Forecast:";
            foreach (array_slice($weatherData['forecast'], 0, 3) as $day) {
                $precip = $day['precipProbability'] > 20 ? " ({$day['precipProbability']}% rain)" : '';
                $context[] = "- {$day['day']}: {$day['high']}°/{$day['low']}°{$precip}";
            }
        }
    } else {
        $context[] = "\nWEATHER: Not configured.";
    }

    // Add current date/time context
    $context[] = "\nCURRENT TIME: " . date('l, F j, Y g:i A');

    return implode("\n", $context);
}

/**
 * Get tile data from cache
 */
function getTileDataForContext(int $userId, string $type): ?array
{
    $cacheKey = "{$type}_{$userId}";
    return cache($cacheKey);
}

/**
 * Call Claude API
 */
function callClaudeAPI(string $systemPrompt, string $userMessage): string
{
    $claudeConfig = config('claude');

    if (empty($claudeConfig['api_key'])) {
        throw new Exception('Claude API key not configured');
    }

    $payload = [
        'model' => $claudeConfig['model'] ?? 'claude-sonnet-4-20250514',
        'max_tokens' => $claudeConfig['max_tokens'] ?? 1024,
        'system' => $systemPrompt,
        'messages' => [
            [
                'role' => 'user',
                'content' => $userMessage
            ]
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

    $response = file_get_contents('https://api.anthropic.com/v1/messages', false, $context);

    if (!$response) {
        throw new Exception('Failed to connect to Claude API');
    }

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        throw new Exception($data['error']['message'] ?? 'Claude API error');
    }

    if (empty($data['content'][0]['text'])) {
        throw new Exception('Empty response from Claude');
    }

    return $data['content'][0]['text'];
}
