<?php
/**
 * Claude AI Suggestions Endpoint - Debug Version
 */

// Register shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        // Clear any output
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        http_response_code(500);
        header('Content-Type: application/json');
        
        $errorMsg = [
            'error' => 'Fatal error occurred',
            'message' => $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line']
        ];
        
        error_log('Fatal error in suggestions.php: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        echo json_encode($errorMsg);
        exit;
    }
});

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Output JSON no matter what
header('Content-Type: application/json');

// Start output buffering to prevent partial output
ob_start();

// Helper functions (defined early so they exist when called; function_exists avoids redeclaration)
if (!function_exists('getTimeOfDay')) {
    function getTimeOfDay(): string
    {
        $hour = (int) date('G');
        if ($hour < 12) return 'morning';
        if ($hour < 17) return 'afternoon';
        if ($hour < 21) return 'evening';
        return 'night';
    }
}

if (!function_exists('buildSuggestionsPrompt')) {
    function buildSuggestionsPrompt(): string
    {
        return <<<'PROMPT'
You are an intelligent personal assistant analyzing a user's dashboard data. Provide actionable suggestions.

Respond with a JSON object containing:
1. "summary": Brief overview (1-2 sentences)
2. "priorities": Array of 1-3 top priorities
3. "suggestions": Array of 3-6 suggestions, each with "text", "type" (urgent/important/followup/planning/wellness), and "source" (email/calendar/crm/tasks/weather/general)

Formatting rules (strict):
- Use plain text only. No emoji. No Unicode symbols.
- Do not use markdown code blocks, blockquotes, or any formatting that would render as boxes or backgrounds.
- Write "summary", "priorities", and each suggestion "text" as simple, clear sentences. Use only standard punctuation and line breaks where needed.
- Keep wording concise and professional.

IMPORTANT: Respond ONLY with valid JSON. No other text before or after the JSON.
PROMPT;
    }
}

if (!function_exists('buildDataMessage')) {
    function buildDataMessage(array $data): string
    {
        $message = "Current time: " . ($data['currentTime'] ?? date('Y-m-d H:i:s')) . " (" . ($data['dayOfWeek'] ?? date('l')) . " " . ($data['timeOfDay'] ?? getTimeOfDay()) . ")\n\n";

        $crm = $data['crm'] ?? [];
        if (!empty($crm['connected']) && !empty($crm['actions']) && is_array($crm['actions'])) {
            $message .= "=== CRM ACTIONS ===\n";
            foreach ($crm['actions'] as $action) {
                if (!is_array($action)) continue;
                $overdue = !empty($action['isOverdue']) ? ' [OVERDUE]' : '';
                $contactName = $action['contactName'] ?? 'Unknown';
                $actionText = $action['actionText'] ?? 'No description';
                $dueDate = $action['dueDate'] ?? 'No date';
                $message .= "- {$contactName}: {$actionText} (Due: {$dueDate}){$overdue}\n";
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
            $events = $calendar['events'] ?? [];
            $message .= (is_array($events) ? count($events) : 0) . " events today\n";
        }

        $todo = $data['todo'] ?? [];
        if (!empty($todo['connected'])) {
            $message .= "=== TASKS ===\n";
            $tasks = $todo['tasks'] ?? [];
            $message .= (is_array($tasks) ? count($tasks) : 0) . " pending tasks\n";
        }

        return $message;
    }
}

try {
    // Check if required files exist
    $functionsFile = __DIR__ . '/../../includes/functions.php';
    $sessionFile = __DIR__ . '/../../includes/session.php';
    $authFile = __DIR__ . '/../../includes/auth.php';
    
    if (!file_exists($functionsFile)) {
        throw new RuntimeException("functions.php not found at: {$functionsFile}");
    }
    if (!file_exists($sessionFile)) {
        throw new RuntimeException("session.php not found at: {$sessionFile}");
    }
    if (!file_exists($authFile)) {
        throw new RuntimeException("auth.php not found at: {$authFile}");
    }
    
    require_once $functionsFile;
    
    // Ensure Database class is loaded for cache function
    if (!class_exists('Database')) {
        require_once __DIR__ . '/../../config/database.php';
    }
    
    require_once $sessionFile;
    require_once $authFile;

    // Initialize session first
    Session::init();

    // Require authentication
    if (!Auth::check()) {
        ob_end_clean();
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // Require AJAX request
    if (!isAjax()) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['error' => 'Invalid request - not AJAX']);
        exit;
    }

    // Verify CSRF
    if (!Auth::verifyCsrf()) {
        ob_end_clean();
        http_response_code(403);
        // Log the issue for debugging (only in debug mode)
        if (config('app.debug', false)) {
            $csrfHeaders = array_filter(array_keys($_SERVER), function($k) {
                return stripos($k, 'CSRF') !== false || stripos($k, 'X_REQUESTED') !== false;
            });
            error_log('CSRF verification failed. Available CSRF-related headers: ' . implode(', ', $csrfHeaders));
        }
        echo json_encode(['error' => 'Invalid security token. Please refresh the page.']);
        exit;
    }

    $userId = Auth::id();
    
    if (!$userId || $userId <= 0) {
        ob_end_clean();
        http_response_code(401);
        echo json_encode(['error' => 'Invalid user ID']);
        exit;
    }

    // Check cache for suggestions
    $cacheKey = "suggestions_{$userId}";
    
    try {
        $cached = cache($cacheKey);
    } catch (Throwable $e) {
        error_log('Cache check error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $cached = null;
    }

    if ($cached !== null) {
        ob_end_clean();
        http_response_code(200);
        echo json_encode($cached);
        exit;
    }

    // Get data from cache only (don't try to fetch directly - that might be causing the crash)
    try {
        $crmData = cache("crm_{$userId}");
        $weatherData = cache("weather_{$userId}");
        $emailData = cache("email_{$userId}");
        $calendarData = cache("calendar_{$userId}");
        $todoData = cache("todo_{$userId}");
    } catch (Exception $e) {
        error_log('Cache retrieval error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $crmData = null;
        $weatherData = null;
        $emailData = null;
        $calendarData = null;
        $todoData = null;
    } catch (Throwable $e) {
        error_log('Cache retrieval error (throwable): ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        $crmData = null;
        $weatherData = null;
        $emailData = null;
        $calendarData = null;
        $todoData = null;
    }

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
        http_response_code(200);
        echo json_encode([
            'suggestions' => [],
            'summary' => 'Connect your services in Settings to get personalized suggestions.',
            'hasData' => false,
            'debug' => $debugInfo
        ]);
        exit;
    }

    // Build data message for Claude
    try {
        $dashboardData = [
            'email' => $emailData ?? null,
            'calendar' => $calendarData ?? null,
            'todo' => $todoData ?? null,
            'crm' => $crmData ?? null,
            'weather' => $weatherData ?? null,
            'currentTime' => date('Y-m-d H:i:s'),
            'dayOfWeek' => date('l'),
            'timeOfDay' => getTimeOfDay()
        ];

        $systemPrompt = buildSuggestionsPrompt();
        $userMessage = buildDataMessage($dashboardData);
    } catch (Exception $e) {
        ob_end_clean();
        http_response_code(500);
        error_log('Error building prompts: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        error_log('Stack trace: ' . $e->getTraceAsString());
        echo json_encode([
            'error' => 'Failed to prepare suggestions request',
            'debug' => config('app.debug', false) ? [
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine()
            ] : null
        ]);
        exit;
    } catch (Throwable $e) {
        ob_end_clean();
        http_response_code(500);
        error_log('Error building prompts (throwable): ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        error_log('Stack trace: ' . $e->getTraceAsString());
        echo json_encode([
            'error' => 'Failed to prepare suggestions request',
            'debug' => config('app.debug', false) ? [
                'message' => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine()
            ] : null
        ]);
        exit;
    }

    // Call Claude API
    $claudeConfig = config('claude');

    if (empty($claudeConfig) || empty($claudeConfig['api_key'])) {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['error' => 'Claude API key not configured. Please set it in config/config.php']);
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

    $response = file_get_contents('https://api.anthropic.com/v1/messages', false, $context);

    if ($response === false) {
        ob_end_clean();
        http_response_code(502);
        $errorMsg = 'Failed to connect to Claude API';
        // Check HTTP response headers if available
        if (isset($http_response_header) && !empty($http_response_header)) {
            $statusLine = $http_response_header[0] ?? '';
            $errorMsg .= ' - ' . $statusLine;
        }
        // Log the error for debugging
        error_log('Claude API connection failed: ' . ($http_response_header[0] ?? 'No response'));
        echo json_encode(['error' => $errorMsg]);
        exit;
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        ob_end_clean();
        http_response_code(502);
        error_log('Claude API JSON decode error: ' . json_last_error_msg() . ' - Response: ' . substr($response, 0, 500));
        echo json_encode(['error' => 'Invalid response from Claude API']);
        exit;
    }

    if (isset($data['error'])) {
        ob_end_clean();
        http_response_code(502);
        $apiError = 'Claude API: ' . ($data['error']['message'] ?? 'Unknown error');
        error_log('Claude API error: ' . $apiError);
        echo json_encode(['error' => $apiError]);
        exit;
    }

    if (empty($data['content']) || empty($data['content'][0]['text'])) {
        ob_end_clean();
        http_response_code(502);
        error_log('Claude API empty response - Data: ' . json_encode($data));
        echo json_encode(['error' => 'Empty response from Claude API']);
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
    try {
        // Use a callable that returns the result
        cache($cacheKey, function() use ($result) {
            return $result;
        }, 600);
    } catch (Exception $e) {
        error_log('Cache save error (non-fatal): ' . $e->getMessage());
    } catch (Throwable $e) {
        error_log('Cache save error (non-fatal, throwable): ' . $e->getMessage());
    }

    ob_end_clean();
    http_response_code(200);
    echo json_encode($result);

} catch (Throwable $e) {
    // Ensure output buffer is clean
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    http_response_code(500);
    
    // Log the full error for debugging
    $errorDetails = [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ];
    
    error_log('Suggestions endpoint error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    // Return user-friendly error message
    $errorMessage = 'Failed to load suggestions';
    $debugInfo = [];
    
    if (config('app.debug', false)) {
        $errorMessage .= ': ' . $e->getMessage();
        $debugInfo = [
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'type' => get_class($e)
        ];
    }
    
    echo json_encode(array_merge([
        'error' => $errorMessage
    ], $debugInfo));
    exit;
}
