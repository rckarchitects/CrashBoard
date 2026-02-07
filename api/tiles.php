<?php
/**
 * Tiles API Endpoint
 *
 * Returns tile data based on type.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

// Initialize session
Session::init();

// Cron warm-up: allow refresh when valid cron secret and user_id are provided (no session)
$cronSecret = config('cron.secret', '');
$cronToken = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
$cronUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($cronSecret !== '' && $cronToken !== '' && hash_equals($cronSecret, $cronToken) && $cronUserId > 0) {
    define('CRON_WARM_USER_ID', $cronUserId);
}

// Require authentication (skip for cron warm-up)
if (!defined('CRON_WARM_USER_ID') && !Auth::check()) {
    jsonError('Unauthorized', 401);
}

// For cron warm-up, skip normal request handling (handled at end of file)
if (!defined('CRON_WARM_USER_ID')) {
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
    $tileId = isset($input['tile_id']) ? (int)$input['tile_id'] : 0;
    $userId = Auth::id();

    // Validate tile ID for notes (required)
    if ($tileType === 'notes' && $tileId <= 0) {
        jsonError('Invalid tile ID for notes tile', 400);
    }

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
    case 'weather':
        jsonResponse(getWeatherData($userId));
        break;
    case 'notes':
        try {
            jsonResponse(getNotesData($userId, $tileId));
        } catch (Exception $e) {
            logMessage('Notes tile error: ' . $e->getMessage(), 'error');
            jsonError('Failed to load notes: ' . $e->getMessage(), 500);
        }
        break;
    case 'notes-list':
        try {
            jsonResponse(getNotesListData($userId));
        } catch (Exception $e) {
            logMessage('Notes list error: ' . $e->getMessage(), 'error');
            jsonError('Failed to load notes list: ' . $e->getMessage(), 500);
        }
        break;
    case 'bookmarks':
        try {
            jsonResponse(getBookmarksData($userId));
        } catch (Exception $e) {
            logMessage('Bookmarks error: ' . $e->getMessage(), 'error');
            jsonError('Failed to load bookmarks: ' . $e->getMessage(), 500);
        }
        break;
    default:
        jsonError('Unknown tile type', 400);
    }
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

    // Ensure column exists so SELECT never throws (e.g. if user never visited settings)
    try {
        $col = Database::query("SHOW COLUMNS FROM users LIKE 'email_preview_chars'");
        if (empty($col)) {
            Database::execute('ALTER TABLE users ADD COLUMN email_preview_chars INT UNSIGNED DEFAULT 320 AFTER updated_at');
        }
    } catch (Exception $e) {
        // ignore
    }

    // User's preview length affects cached output, so read it before cache lookup
    $previewFullLen = 320;
    try {
        $userRow = Database::queryOne('SELECT email_preview_chars FROM users WHERE id = ?', [$userId]);
        if ($userRow !== null) {
            // Support different column name casing from driver
            $raw = $userRow['email_preview_chars'] ?? $userRow['EMAIL_PREVIEW_CHARS'] ?? 320;
            if ($raw !== null && $raw !== '') {
                $previewFullLen = max(100, min(2000, (int) $raw));
            }
        }
    } catch (Exception $e) {
        // use default 320
    }

    // Cache key includes preview length so changing the setting gets fresh data
    $cacheKey = "email_{$userId}_{$previewFullLen}";
    $cached = cache($cacheKey);

    if ($cached !== null) {
        return $cached;
    }

    try {
        // Microsoft bodyPreview is limited to 255 chars; only request full body when user wants more
        $needBody = $previewFullLen > 255;
        $select = 'id,subject,from,receivedDateTime,isRead,bodyPreview,flag';
        if ($needBody) {
            $select .= ',body';
        }

        // Fetch recent messages (no filter – flag filter not supported on API); filter in PHP for unread or flagged
        $response = callMicrosoftGraph($token, '/me/mailFolders/inbox/messages', [
            '$top' => 200,
            '$select' => $select,
            '$orderby' => 'receivedDateTime desc',
        ]);

        $candidates = [];
        foreach ($response['value'] ?? [] as $email) {
            $isRead = $email['isRead'] ?? true;
            $flagStatus = $email['flag']['flagStatus'] ?? 'notFlagged';
            $isFlagged = $flagStatus === 'flagged';
            if (!$isRead || $isFlagged) {
                $rawPreview = '';
                if ($needBody && !empty($email['body']['content'])) {
                    $content = $email['body']['content'];
                    $contentType = $email['body']['contentType'] ?? 'text';
                    $rawPreview = $contentType === 'html'
                        ? trim(preg_replace('/\s+/', ' ', strip_tags($content)))
                        : $content;
                    $rawPreview = html_entity_decode($rawPreview, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                if ($rawPreview === '') {
                    $rawPreview = $email['bodyPreview'] ?? '';
                    $rawPreview = html_entity_decode($rawPreview, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                $preview = truncate($rawPreview, 100);
                $previewFull = truncate($rawPreview, $previewFullLen);

                $candidates[] = [
                    'id' => $email['id'],
                    'subject' => $email['subject'] ?? '(No Subject)',
                    'from' => $email['from']['emailAddress']['name'] ?? $email['from']['emailAddress']['address'] ?? 'Unknown',
                    'preview' => $preview,
                    'previewFull' => $previewFull,
                    'receivedDateTime' => $email['receivedDateTime'] ?? '',
                    'receivedTime' => formatRelativeTime($email['receivedDateTime'] ?? ''),
                    'isRead' => $isRead,
                    'isFlagged' => $isFlagged,
                ];
            }
        }
        $emails = array_slice($candidates, 0, 10);

        $result = [
            'connected' => true,
            'emails' => $emails,
            'unreadCount' => count($emails),
        ];

        // Cache for 5 minutes (key with length for this user's preview setting)
        cache($cacheKey, fn() => $result, config('refresh.email', 300));
        // Also cache under generic key so suggestions API can read email data
        cache("email_{$userId}", fn() => $result, config('refresh.email', 300));

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
 *
 * Which tasks are included is controlled by config('microsoft.todo_show'):
 * - 'all'     = all incomplete tasks from the default Tasks list (single list, v1.0). Reliable.
 * - 'my_day' = only tasks added to "My Day" in To Do. Tries Graph beta $filter=isInMyDay eq true
 *              across all lists; if beta/filter fails, falls back to same as 'all'.
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

    $todoShow = config('microsoft.todo_show', 'all');
    $tasksSource = 'all_incomplete'; // will be set to 'my_day' if we successfully use My Day filter

    try {
        $listsResponse = callMicrosoftGraph($token, '/me/todo/lists', ['$top' => 50]);

        if (empty($listsResponse['value'])) {
            return ['connected' => true, 'tasks' => [], 'tasks_source' => $tasksSource, 'tasks_source_label' => 'No lists'];
        }

        $firstListId = $listsResponse['value'][0]['id'];
        $tasksResponse = ['value' => []];

        if ($todoShow === 'my_day') {
            // Try to get only "My Day" tasks: beta API with $filter per list
            try {
                $allTasks = [];
                foreach ($listsResponse['value'] as $list) {
                    $listId = $list['id'];
                    $resp = callMicrosoftGraphBeta($token, "/me/todo/lists/{$listId}/tasks", [
                        '$top' => 100,
                        '$filter' => "isInMyDay eq true and status ne 'completed'",
                    ]);
                    foreach ($resp['value'] ?? [] as $task) {
                        $allTasks[] = $task;
                    }
                }
                $tasksResponse = ['value' => $allTasks];
                $tasksSource = 'my_day';
            } catch (Exception $e) {
                logMessage('Todo My Day filter not supported, using all incomplete: ' . $e->getMessage(), 'info');
                $todoShow = 'all';
            }
        }

        if ($todoShow === 'all' && empty($tasksResponse['value'])) {
            // All incomplete tasks from the default (first) list only
            try {
                $tasksResponse = callMicrosoftGraphBeta($token, "/me/todo/lists/{$firstListId}/tasks", ['$top' => 100]);
            } catch (Exception $e) {
                $tasksResponse = callMicrosoftGraph($token, "/me/todo/lists/{$firstListId}/tasks", ['$top' => 50]);
            }
        }

        // Build task list: exclude completed; when source was My Day filter we already have only My Day
        $tasks = [];
        foreach ($tasksResponse['value'] ?? [] as $task) {
            if (($task['status'] ?? '') === 'completed') {
                continue;
            }
            if ($tasksSource === 'my_day') {
                // Already filtered by API; optional extra check if isInMyDay present
                if (array_key_exists('isInMyDay', $task) && !($task['isInMyDay'] ?? false)) {
                    continue;
                }
            }
            $dueDate = null;
            $dueDateTime = null;
            if (!empty($task['dueDateTime']['dateTime'])) {
                $dueDateTime = $task['dueDateTime']['dateTime'];
                $dueDate = formatDate($dueDateTime, 'M j');
            }
            $tasks[] = [
                'id' => $task['id'],
                'title' => $task['title'] ?? '(No Title)',
                'importance' => $task['importance'] ?? 'normal',
                'dueDate' => $dueDate,
                'dueDateTime' => $dueDateTime,
                'completed' => ($task['status'] ?? '') === 'completed',
            ];
        }

        usort($tasks, function ($a, $b) {
            $impOrder = ['high' => 0, 'normal' => 1, 'low' => 2];
            $cmp = ($impOrder[$a['importance']] ?? 1) <=> ($impOrder[$b['importance']] ?? 1);
            if ($cmp !== 0) {
                return $cmp;
            }
            if ($a['dueDateTime'] === null && $b['dueDateTime'] === null) {
                return 0;
            }
            if ($a['dueDateTime'] === null) {
                return 1;
            }
            if ($b['dueDateTime'] === null) {
                return -1;
            }
            return strcmp($a['dueDateTime'], $b['dueDateTime']);
        });
        $tasks = array_slice($tasks, 0, 15);

        $tasksSourceLabel = $tasksSource === 'my_day' ? 'My Day' : 'All incomplete (default list)';

        $result = [
            'connected' => true,
            'tasks' => $tasks,
            'tasks_source' => $tasksSource,
            'tasks_source_label' => $tasksSourceLabel,
        ];

        cache($cacheKey, fn() => $result, config('refresh.todo', 300));

        return $result;
    } catch (Exception $e) {
        logMessage('Todo fetch error: ' . $e->getMessage(), 'error');
        return [
            'connected' => true,
            'error' => 'Failed to fetch tasks: ' . $e->getMessage(),
        ];
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

        if (!$credentials || empty($credentials['user_id']) || empty($credentials['api_key'])) {
            return ['connected' => false, 'error' => 'Invalid stored credentials'];
        }

        // Get user's email to filter actions assigned to them
        $user = Database::queryOne('SELECT email FROM users WHERE id = ?', [$userId]);
        $userEmail = $user['email'] ?? '';

        // Filter by assignee (the user_id in OnePageCRM credentials is the user's ID in their system)
        $response = callOnePageCRM($baseUrl, '/actions.json', $credentials, [
            'status' => 'asap,date,date_time,waiting',
            'assignee_id' => $credentials['user_id'],
            'per_page' => 10
        ]);

        // OnePageCRM returns data in 'data' -> 'actions' array
        $actionsData = $response['data']['actions'] ?? [];

        // Collect all unique contact IDs to fetch in one batch
        $contactIds = [];
        foreach ($actionsData as $item) {
            $action = $item['action'] ?? $item;
            if (!empty($action['contact_id'])) {
                $contactIds[$action['contact_id']] = true;
            }
        }

        // Fetch contact details for all contact IDs
        $contacts = [];
        foreach (array_keys($contactIds) as $contactId) {
            try {
                $contactResponse = callOnePageCRM($baseUrl, "/contacts/{$contactId}.json", $credentials, []);
                $contactData = $contactResponse['data']['contact'] ?? null;
                if ($contactData) {
                    $firstName = $contactData['first_name'] ?? '';
                    $lastName = $contactData['last_name'] ?? '';
                    $name = trim($firstName . ' ' . $lastName);
                    if (empty(trim($name)) && !empty($contactData['company_name'])) {
                        $name = $contactData['company_name'];
                    }
                    $contacts[$contactId] = !empty(trim($name)) ? $name : 'Unknown Contact';
                } else {
                    $contacts[$contactId] = 'Unknown Contact';
                }
            } catch (Exception $e) {
                // Log the error for debugging
                logMessage("Failed to fetch contact {$contactId}: " . $e->getMessage(), 'error');
                $contacts[$contactId] = 'Unknown Contact';
            }
        }

        $actions = [];
        foreach ($actionsData as $item) {
            $action = $item['action'] ?? $item;

            $dueDate = $action['date'] ?? null;
            $isOverdue = false;
            $dueDateFormatted = 'No date';

            if ($dueDate) {
                $dueTimestamp = strtotime($dueDate);
                $isOverdue = $dueTimestamp < strtotime('today');

                // Include year if not current year, or if overdue
                $currentYear = date('Y');
                $dueYear = date('Y', $dueTimestamp);

                if ($isOverdue || $dueYear !== $currentYear) {
                    $dueDateFormatted = formatDate($dueDate, 'M j, Y');
                } else {
                    $dueDateFormatted = formatDate($dueDate, 'M j');
                }
            }

            // Get contact name from our fetched contacts
            $contactId = $action['contact_id'] ?? '';
            $contactName = $contacts[$contactId] ?? 'Unknown Contact';

            $actions[] = [
                'id' => $action['id'] ?? '',
                'contactName' => $contactName,
                'actionText' => $action['text'] ?? 'Action',
                'dueDate' => $dueDateFormatted,
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
        return ['connected' => true, 'error' => 'Failed to fetch CRM data: ' . $e->getMessage()];
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
 * Call Microsoft Graph API (v1.0)
 */
function callMicrosoftGraph(string $token, string $endpoint, array $params = []): array
{
    return callMicrosoftGraphBase($token, $endpoint, $params, 'v1.0');
}

/**
 * Call Microsoft Graph API (beta) - for properties like todoTask.isInMyDay
 */
function callMicrosoftGraphBeta(string $token, string $endpoint, array $params = []): array
{
    return callMicrosoftGraphBase($token, $endpoint, $params, 'beta');
}

/**
 * Call Microsoft Graph API (shared implementation)
 */
function callMicrosoftGraphBase(string $token, string $endpoint, array $params, string $version): array
{
    $url = 'https://graph.microsoft.com/' . $version . $endpoint;

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
 *
 * OnePageCRM uses HTTP Basic Authentication.
 * See: https://developer.onepagecrm.com/api/
 */
function callOnePageCRM(string $baseUrl, string $endpoint, array $credentials, array $params = []): array
{
    $userId = $credentials['user_id'];
    $apiKey = $credentials['api_key'];

    $url = $baseUrl . $endpoint;

    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    // Basic Auth: base64 encode "user_id:api_key"
    $authString = base64_encode("{$userId}:{$apiKey}");

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", [
                "Authorization: Basic {$authString}",
                "Content-Type: application/json",
                "Accept: application/json"
            ]),
            'ignore_errors' => true,
            'timeout' => 30
        ]
    ]);

    $response = file_get_contents($url, false, $context);

    if ($response === false) {
        throw new Exception('Failed to connect to OnePageCRM API');
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON response from OnePageCRM');
    }

    // Check for API errors
    if (isset($data['status']) && $data['status'] !== 0) {
        $errorMsg = $data['message'] ?? 'Unknown OnePageCRM API error';
        throw new Exception("OnePageCRM API error: {$errorMsg}");
    }

    return $data;
}

/**
 * Get weather data from Open-Meteo API
 * Free API, no key required: https://open-meteo.com/
 */
function getWeatherData(int $userId): array
{
    // First check user-specific settings in database
    $weatherRow = Database::queryOne(
        'SELECT access_token FROM oauth_tokens WHERE user_id = ? AND provider = ?',
        [$userId, 'weather']
    );

    $weatherConfig = null;
    if ($weatherRow) {
        $weatherConfig = json_decode($weatherRow['access_token'], true);
    }

    // Fall back to config file if no user settings
    if (!$weatherConfig || empty($weatherConfig['latitude']) || empty($weatherConfig['longitude'])) {
        $weatherConfig = config('weather');
    }

    if (!$weatherConfig || empty($weatherConfig['latitude']) || empty($weatherConfig['longitude'])) {
        return ['configured' => false];
    }

    $cacheKey = "weather_{$userId}";
    $cached = cache($cacheKey);

    if ($cached !== null) {
        return $cached;
    }

    try {
        $latitude = $weatherConfig['latitude'];
        $longitude = $weatherConfig['longitude'];
        $locationName = $weatherConfig['location_name'] ?? 'Your Location';
        $units = $weatherConfig['units'] ?? 'celsius';

        // Build API URL
        $tempUnit = $units === 'fahrenheit' ? 'fahrenheit' : 'celsius';
        $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,weather_code,wind_speed_10m',
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max',
            'temperature_unit' => $tempUnit,
            'wind_speed_unit' => 'mph',
            'timezone' => config('app.timezone', 'auto'),
            'forecast_days' => 5
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'Accept: application/json',
                'ignore_errors' => true,
                'timeout' => 10
            ]
        ]);

        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            throw new Exception('Failed to connect to weather API');
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from weather API');
        }

        if (isset($data['error'])) {
            throw new Exception($data['reason'] ?? 'Weather API error');
        }

        // Parse current weather
        $current = $data['current'] ?? [];
        $daily = $data['daily'] ?? [];

        $result = [
            'configured' => true,
            'location' => $locationName,
            'units' => $units === 'fahrenheit' ? '°F' : '°C',
            'current' => [
                'temperature' => round($current['temperature_2m'] ?? 0),
                'feelsLike' => round($current['apparent_temperature'] ?? 0),
                'humidity' => $current['relative_humidity_2m'] ?? 0,
                'windSpeed' => round($current['wind_speed_10m'] ?? 0),
                'weatherCode' => $current['weather_code'] ?? 0,
                'description' => getWeatherDescription($current['weather_code'] ?? 0),
                'icon' => getWeatherIcon($current['weather_code'] ?? 0),
            ],
            'forecast' => []
        ];

        // Parse 5-day forecast
        if (!empty($daily['time'])) {
            $timezone = config('app.timezone', 'UTC');
            for ($i = 0; $i < min(5, count($daily['time'])); $i++) {
                $date = new DateTime($daily['time'][$i], new DateTimeZone($timezone));
                $result['forecast'][] = [
                    'day' => $i === 0 ? 'Today' : $date->format('D'),
                    'date' => $date->format('M j'),
                    'high' => round($daily['temperature_2m_max'][$i] ?? 0),
                    'low' => round($daily['temperature_2m_min'][$i] ?? 0),
                    'precipProbability' => $daily['precipitation_probability_max'][$i] ?? 0,
                    'weatherCode' => $daily['weather_code'][$i] ?? 0,
                    'icon' => getWeatherIcon($daily['weather_code'][$i] ?? 0),
                ];
            }
        }

        // Cache for 30 minutes
        cache($cacheKey, fn() => $result, config('refresh.weather', 1800));

        return $result;
    } catch (Exception $e) {
        logMessage('Weather fetch error: ' . $e->getMessage(), 'error');
        return ['configured' => true, 'error' => 'Failed to fetch weather data'];
    }
}

/**
 * Get weather description from WMO weather code
 * See: https://open-meteo.com/en/docs
 */
function getWeatherDescription(int $code): string
{
    $descriptions = [
        0 => 'Clear sky',
        1 => 'Mainly clear',
        2 => 'Partly cloudy',
        3 => 'Overcast',
        45 => 'Foggy',
        48 => 'Rime fog',
        51 => 'Light drizzle',
        53 => 'Drizzle',
        55 => 'Dense drizzle',
        56 => 'Freezing drizzle',
        57 => 'Dense freezing drizzle',
        61 => 'Light rain',
        63 => 'Rain',
        65 => 'Heavy rain',
        66 => 'Freezing rain',
        67 => 'Heavy freezing rain',
        71 => 'Light snow',
        73 => 'Snow',
        75 => 'Heavy snow',
        77 => 'Snow grains',
        80 => 'Light showers',
        81 => 'Showers',
        82 => 'Heavy showers',
        85 => 'Light snow showers',
        86 => 'Snow showers',
        95 => 'Thunderstorm',
        96 => 'Thunderstorm with hail',
        99 => 'Thunderstorm with heavy hail',
    ];

    return $descriptions[$code] ?? 'Unknown';
}

/**
 * Get weather icon class from WMO weather code
 */
function getWeatherIcon(int $code): string
{
    // Map codes to icon names
    if ($code === 0) return 'sun';
    if ($code <= 2) return 'cloud-sun';
    if ($code === 3) return 'cloud';
    if ($code >= 45 && $code <= 48) return 'fog';
    if ($code >= 51 && $code <= 57) return 'cloud-drizzle';
    if ($code >= 61 && $code <= 67) return 'cloud-rain';
    if ($code >= 71 && $code <= 77) return 'snowflake';
    if ($code >= 80 && $code <= 82) return 'cloud-showers';
    if ($code >= 85 && $code <= 86) return 'cloud-snow';
    if ($code >= 95) return 'bolt';

    return 'cloud';
}

/**
 * Get notes data for a specific tile
 */
function getNotesData(int $userId, int $tileId): array
{
    if ($tileId <= 0) {
        return ['notes' => '', 'saved_at' => null, 'current_note_id' => null];
    }

    // Get notes from tile settings JSON
    $tile = Database::queryOne(
        'SELECT settings, updated_at FROM tiles WHERE id = ? AND user_id = ?',
        [$tileId, $userId]
    );

    if (!$tile) {
        // Tile doesn't exist or doesn't belong to user - return empty notes
        return ['notes' => '', 'saved_at' => null, 'current_note_id' => null];
    }

    $settings = json_decode($tile['settings'] ?? '{}', true);
    if (!is_array($settings)) {
        $settings = [];
    }
    
    $notes = $settings['notes'] ?? '';
    $currentNoteId = isset($settings['current_note_id']) ? (int)$settings['current_note_id'] : null;

    return [
        'notes' => $notes,
        'saved_at' => $tile['updated_at'] ?? null,
        'current_note_id' => $currentNoteId
    ];
}

/**
 * Get bookmarks for the user
 */
function getBookmarksData(int $userId): array
{
    try {
        $tableExists = Database::queryOne("SHOW TABLES LIKE 'bookmarks'");
        if (empty($tableExists)) {
            return ['bookmarks' => []];
        }
    } catch (Exception $e) {
        return ['bookmarks' => []];
    }

    $rows = Database::query(
        'SELECT id, url, title, created_at FROM bookmarks WHERE user_id = ? ORDER BY created_at DESC',
        [$userId]
    );

    return [
        'bookmarks' => array_map(function ($row) {
            return [
                'id' => (int) $row['id'],
                'url' => $row['url'],
                'title' => $row['title'],
                'created_at' => $row['created_at'] ?? null,
            ];
        }, $rows),
    ];
}

/**
 * Get list of saved notes for the user
 */
function getNotesListData(int $userId): array
{
    // Ensure notes table exists (migration)
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

    // Get all notes for user, ordered by most recent first
    $notes = Database::query(
        'SELECT id, content, created_at, updated_at FROM notes WHERE user_id = ? ORDER BY created_at DESC LIMIT 50',
        [$userId]
    );

    return [
        'notes' => array_map(function($note) {
            return [
                'id' => (int)$note['id'],
                'content' => $note['content'],
                'created_at' => $note['created_at'],
                'preview' => mb_substr($note['content'], 0, 100) . (mb_strlen($note['content']) > 100 ? '...' : '')
            ];
        }, $notes)
    ];
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

// Cron warm-up: refresh cache for the requested user (notes and AI suggestions are excluded)
if (defined('CRON_WARM_USER_ID')) {
    $uid = CRON_WARM_USER_ID;
    cacheClear("email_{$uid}");
    cacheClear("calendar_{$uid}");
    cacheClear("todo_{$uid}");
    cacheClear("crm_{$uid}");
    cacheClear("weather_{$uid}");
    getEmailData($uid);
    getCalendarData($uid);
    getTodoData($uid);
    getCrmData($uid);
    getWeatherData($uid);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'user_id' => $uid]);
    exit;
}
