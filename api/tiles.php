<?php
/**
 * Tiles API Endpoint
 *
 * Returns tile data based on type.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

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
$tileType = $input['type'] ?? '';
$tileId = $input['tile_id'] ?? 0;
$userId = Auth::id();

// Route to appropriate handler
switch ($tileType) {
    case 'email':
        jsonResponse(getEmailData($userId));
        break;
    case 'calendar':
        jsonResponse(getCalendarData($userId));
        break;
    case 'todo':
        jsonResponse(getTodoData($userId));
        break;
    case 'crm':
        jsonResponse(getCrmData($userId));
        break;
    default:
        jsonError('Unknown tile type', 400);
}

/**
 * Get email data from Microsoft Graph API
 */
function getEmailData(int $userId): array
{
    $token = getOAuthToken($userId, 'microsoft');

    if (!$token) {
        return ['connected' => false];
    }

    // Try to get from cache first
    $cacheKey = "email_{$userId}";
    $cached = cache($cacheKey);

    if ($cached !== null) {
        return $cached;
    }

    try {
        $response = callMicrosoftGraph($token, '/me/mailFolders/inbox/messages', [
            '$top' => 10,
            '$select' => 'subject,from,receivedDateTime,isRead,bodyPreview',
            '$orderby' => 'receivedDateTime desc',
            '$filter' => 'isRead eq false'
        ]);

        $emails = [];
        foreach ($response['value'] ?? [] as $email) {
            $emails[] = [
                'id' => $email['id'],
                'subject' => $email['subject'] ?? '(No Subject)',
                'from' => $email['from']['emailAddress']['name'] ?? $email['from']['emailAddress']['address'] ?? 'Unknown',
                'preview' => truncate($email['bodyPreview'] ?? '', 100),
                'receivedTime' => formatRelativeTime($email['receivedDateTime']),
                'isRead' => $email['isRead'] ?? false,
            ];
        }

        // Get unread count
        $countResponse = callMicrosoftGraph($token, '/me/mailFolders/inbox', [
            '$select' => 'unreadItemCount'
        ]);

        $result = [
            'connected' => true,
            'emails' => $emails,
            'unreadCount' => $countResponse['unreadItemCount'] ?? count($emails),
        ];

        // Cache for 5 minutes
        cache($cacheKey, fn() => $result, config('refresh.email', 300));

        return $result;
    } catch (Exception $e) {
        logMessage('Email fetch error: ' . $e->getMessage(), 'error');
        return ['connected' => true, 'error' => 'Failed to fetch emails'];
    }
}

/**
 * Get calendar data from Microsoft Graph API
 */
function getCalendarData(int $userId): array
{
    $token = getOAuthToken($userId, 'microsoft');

    if (!$token) {
        return ['connected' => false];
    }

    $cacheKey = "calendar_{$userId}";
    $cached = cache($cacheKey);

    if ($cached !== null) {
        return $cached;
    }

    try {
        $timezone = config('app.timezone', 'UTC');
        $startOfDay = (new DateTime('today', new DateTimeZone($timezone)))->format('c');
        $endOfDay = (new DateTime('tomorrow', new DateTimeZone($timezone)))->format('c');

        $response = callMicrosoftGraph($token, '/me/calendarView', [
            'startDateTime' => $startOfDay,
            'endDateTime' => $endOfDay,
            '$select' => 'subject,start,end,location,isAllDay',
            '$orderby' => 'start/dateTime',
            '$top' => 10
        ]);

        $events = [];
        foreach ($response['value'] ?? [] as $event) {
            $startTime = new DateTime($event['start']['dateTime'], new DateTimeZone($event['start']['timeZone'] ?? 'UTC'));
            $startTime->setTimezone(new DateTimeZone($timezone));

            $events[] = [
                'id' => $event['id'],
                'subject' => $event['subject'] ?? '(No Title)',
                'startTime' => $event['isAllDay'] ? 'All Day' : $startTime->format('g:i A'),
                'location' => $event['location']['displayName'] ?? null,
                'isAllDay' => $event['isAllDay'] ?? false,
            ];
        }

        $result = [
            'connected' => true,
            'events' => $events,
        ];

        cache($cacheKey, fn() => $result, config('refresh.calendar', 600));

        return $result;
    } catch (Exception $e) {
        logMessage('Calendar fetch error: ' . $e->getMessage(), 'error');
        return ['connected' => true, 'error' => 'Failed to fetch calendar'];
    }
}

/**
 * Get todo/tasks data from Microsoft Graph API
 */
function getTodoData(int $userId): array
{
    $token = getOAuthToken($userId, 'microsoft');

    if (!$token) {
        return ['connected' => false];
    }

    $cacheKey = "todo_{$userId}";
    $cached = cache($cacheKey);

    if ($cached !== null) {
        return $cached;
    }

    try {
        // Get default task list
        $listsResponse = callMicrosoftGraph($token, '/me/todo/lists', [
            '$top' => 1
        ]);

        if (empty($listsResponse['value'])) {
            return ['connected' => true, 'tasks' => []];
        }

        $listId = $listsResponse['value'][0]['id'];

        // Get tasks
        $tasksResponse = callMicrosoftGraph($token, "/me/todo/lists/{$listId}/tasks", [
            '$filter' => 'status ne \'completed\'',
            '$select' => 'title,importance,dueDateTime,status',
            '$orderby' => 'importance desc,dueDateTime/dateTime',
            '$top' => 15
        ]);

        $tasks = [];
        foreach ($tasksResponse['value'] ?? [] as $task) {
            $dueDate = null;
            if (!empty($task['dueDateTime']['dateTime'])) {
                $dueDate = formatDate($task['dueDateTime']['dateTime'], 'M j');
            }

            $tasks[] = [
                'id' => $task['id'],
                'title' => $task['title'] ?? '(No Title)',
                'importance' => $task['importance'] ?? 'normal',
                'dueDate' => $dueDate,
                'completed' => ($task['status'] ?? '') === 'completed',
            ];
        }

        $result = [
            'connected' => true,
            'tasks' => $tasks,
        ];

        cache($cacheKey, fn() => $result, config('refresh.todo', 300));

        return $result;
    } catch (Exception $e) {
        logMessage('Todo fetch error: ' . $e->getMessage(), 'error');
        return ['connected' => true, 'error' => 'Failed to fetch tasks'];
    }
}

/**
 * Get CRM data from OnePageCRM API
 */
function getCrmData(int $userId): array
{
    $token = getOAuthToken($userId, 'onepagecrm');

    if (!$token) {
        return ['connected' => false];
    }

    $cacheKey = "crm_{$userId}";
    $cached = cache($cacheKey);

    if ($cached !== null) {
        return $cached;
    }

    try {
        $crmConfig = config('onepagecrm');
        $baseUrl = $crmConfig['base_url'] ?? 'https://app.onepagecrm.com/api/v3';

        // Decrypt stored credentials
        $credentials = json_decode(decrypt($token), true);

        $response = callOnePageCRM($baseUrl, '/actions.json', $credentials, [
            'status' => 'asap,date,date_time,waiting',
            'per_page' => 10
        ]);

        $actions = [];
        foreach ($response['data']['actions'] ?? [] as $action) {
            $dueDate = $action['date'] ?? null;
            $isOverdue = false;

            if ($dueDate) {
                $isOverdue = strtotime($dueDate) < strtotime('today');
                $dueDate = formatDate($dueDate, 'M j');
            }

            $actions[] = [
                'id' => $action['id'],
                'contactName' => $action['contact_name'] ?? 'Unknown Contact',
                'actionText' => $action['text'] ?? 'Action',
                'dueDate' => $dueDate ?? 'No date',
                'isOverdue' => $isOverdue,
            ];
        }

        $result = [
            'connected' => true,
            'actions' => $actions,
        ];

        cache($cacheKey, fn() => $result, config('refresh.crm', 600));

        return $result;
    } catch (Exception $e) {
        logMessage('CRM fetch error: ' . $e->getMessage(), 'error');
        return ['connected' => true, 'error' => 'Failed to fetch CRM data'];
    }
}

/**
 * Get OAuth token for a provider
 */
function getOAuthToken(int $userId, string $provider): ?string
{
    $token = Database::queryOne(
        'SELECT access_token, refresh_token, expires_at FROM oauth_tokens
         WHERE user_id = ? AND provider = ?',
        [$userId, $provider]
    );

    if (!$token) {
        return null;
    }

    // Check if token is expired
    if ($token['expires_at'] && strtotime($token['expires_at']) < time()) {
        // Try to refresh
        if ($provider === 'microsoft' && $token['refresh_token']) {
            $newToken = refreshMicrosoftToken($token['refresh_token']);
            if ($newToken) {
                // Update stored token
                Database::execute(
                    'UPDATE oauth_tokens SET access_token = ?, expires_at = ? WHERE user_id = ? AND provider = ?',
                    [$newToken['access_token'], date('Y-m-d H:i:s', time() + $newToken['expires_in']), $userId, $provider]
                );
                return $newToken['access_token'];
            }
        }
        return null;
    }

    return $token['access_token'];
}

/**
 * Refresh Microsoft OAuth token
 */
function refreshMicrosoftToken(string $refreshToken): ?array
{
    $msConfig = config('microsoft');

    $response = file_get_contents('https://login.microsoftonline.com/' . $msConfig['tenant_id'] . '/oauth2/v2.0/token', false, stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query([
                'client_id' => $msConfig['client_id'],
                'client_secret' => $msConfig['client_secret'],
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ])
        ]
    ]));

    if (!$response) {
        return null;
    }

    return json_decode($response, true);
}

/**
 * Call Microsoft Graph API
 */
function callMicrosoftGraph(string $token, string $endpoint, array $params = []): array
{
    $url = 'https://graph.microsoft.com/v1.0' . $endpoint;

    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer {$token}\r\nContent-Type: application/json",
            'ignore_errors' => true
        ]
    ]);

    $response = file_get_contents($url, false, $context);

    if (!$response) {
        throw new Exception('Failed to call Microsoft Graph API');
    }

    $data = json_decode($response, true);

    if (isset($data['error'])) {
        throw new Exception($data['error']['message'] ?? 'Graph API error');
    }

    return $data;
}

/**
 * Call OnePageCRM API
 */
function callOnePageCRM(string $baseUrl, string $endpoint, array $credentials, array $params = []): array
{
    $url = $baseUrl . $endpoint;

    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "X-OnePageCRM-UID: {$credentials['user_id']}\r\nX-OnePageCRM-TS: " . time() . "\r\nX-OnePageCRM-Auth: {$credentials['api_key']}\r\nContent-Type: application/json",
            'ignore_errors' => true
        ]
    ]);

    $response = file_get_contents($url, false, $context);

    if (!$response) {
        throw new Exception('Failed to call OnePageCRM API');
    }

    return json_decode($response, true);
}

/**
 * Format relative time
 */
function formatRelativeTime(string $datetime): string
{
    $timestamp = strtotime($datetime);
    $diff = time() - $timestamp;

    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . 'm ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . 'h ago';
    } elseif ($diff < 604800) {
        $days = floor($diff / 86400);
        return $days . 'd ago';
    } else {
        return formatDate($datetime, 'M j');
    }
}
