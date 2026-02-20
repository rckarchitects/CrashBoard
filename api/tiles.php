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

// When included by api/tasks.php for Graph helpers only, skip request handling
if (!defined('CRASHBOARD_LOAD_TILES_FUNCTIONS_ONLY')) {
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
    case 'calendar-heatmap':
        try {
            jsonResponse(getCalendarHeatmapData($userId));
        } catch (Exception $e) {
            logMessage('Calendar heatmap tile error: ' . $e->getMessage(), 'error');
            jsonError('Failed to load calendar heatmap: ' . $e->getMessage(), 500);
        }
        break;
    case 'calendar-next':
        if ($tileId <= 0) {
            jsonError('Invalid tile ID for calendar-next tile', 400);
        }
        try {
            jsonResponse(getNextEventByCategory($userId, $tileId));
        } catch (Exception $e) {
            logMessage('Calendar-next tile error: ' . $e->getMessage(), 'error');
            jsonError('Failed to load next event: ' . $e->getMessage(), 500);
        }
        break;
    case 'next-event':
        if ($tileId <= 0) {
            jsonError('Invalid tile ID for next-event tile', 400);
        }
        try {
            jsonResponse(getNextCalendarEvent($userId, $tileId));
        } catch (Exception $e) {
            logMessage('Next-event tile error: ' . $e->getMessage(), 'error');
            jsonError('Failed to load next event: ' . $e->getMessage(), 500);
        }
        break;
    case 'todo':
        jsonResponse(getTodoData($userId));
        break;
    case 'todo-personal':
        jsonResponse(getTodoPersonalData($userId));
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
    case 'link-board':
        try {
            jsonResponse(getLinkBoardData($userId));
        } catch (Exception $e) {
            logMessage('Link board error: ' . $e->getMessage(), 'error');
            jsonError('Failed to load link board: ' . $e->getMessage(), 500);
        }
        break;
    case 'flagged-email':
        try {
            jsonResponse(getFlaggedEmailData($userId));
        } catch (Exception $e) {
            logMessage('Flagged email tile error: ' . $e->getMessage(), 'error');
            jsonError('Failed to load flagged email: ' . $e->getMessage(), 500);
        }
        break;
    case 'flagged-email-count':
        try {
            jsonResponse(getFlaggedEmailCountData($userId));
        } catch (Exception $e) {
            logMessage('Flagged email count tile error: ' . $e->getMessage(), 'error');
            jsonError('Failed to load flagged email count: ' . $e->getMessage(), 500);
        }
        break;
    case 'overdue-tasks-count':
        try {
            jsonResponse(getOverdueTasksCountData($userId));
        } catch (Exception $e) {
            logMessage('Overdue tasks count tile error: ' . $e->getMessage(), 'error');
            jsonError('Failed to load overdue tasks count: ' . $e->getMessage(), 500);
        }
        break;
    case 'availability':
        try {
            jsonResponse(getAvailabilityData($userId));
        } catch (Exception $e) {
            logMessage('Availability tile error: ' . $e->getMessage(), 'error');
            jsonError('Failed to load availability: ' . $e->getMessage(), 500);
        }
        break;
    case 'train-departures':
        if ($tileId <= 0) {
            jsonError('Invalid tile ID for train-departures tile', 400);
        }
        try {
            jsonResponse(getTrainDeparturesData($userId, $tileId));
        } catch (Exception $e) {
            logMessage('Train departures tile error: ' . $e->getMessage(), 'error');
            jsonError('Failed to load train departures: ' . $e->getMessage(), 500);
        }
        break;
    case 'planner-overview':
        try {
            $planId = isset($input['plan_id']) ? trim((string) $input['plan_id']) : '';
            if ($planId !== '') {
                jsonResponse(getPlannerSinglePlanData($userId, $planId));
            } else {
                jsonResponse(getPlannerOverviewData($userId));
            }
        } catch (Exception $e) {
            logMessage('Planner overview tile error: ' . $e->getMessage(), 'error');
            jsonError('Failed to load Planner overview: ' . $e->getMessage(), 500);
        }
        break;
    default:
        jsonError('Unknown tile type', 400);
    }
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
                        ? htmlToPlainTextWithLineBreaks($content)
                        : $content;
                    $rawPreview = $contentType === 'text'
                        ? html_entity_decode($rawPreview, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                        : $rawPreview;
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
 * Get a random flagged email from Microsoft inbox (for reminder tile).
 * Caches the list of flagged emails; each request returns one random pick from the list.
 */
function getFlaggedEmailData(int $userId): array
{
    $token = getOAuthToken($userId, 'microsoft');

    if (!$token) {
        return ['connected' => false];
    }

    $cacheKey = "flagged_email_{$userId}";
    $ttl = config('refresh.email', 300);
    $cachedList = cache($cacheKey);

    if ($cachedList === null || !is_array($cachedList)) {
        $list = fetchFlaggedEmailsList($token);
        cache($cacheKey, fn() => $list, $ttl);
    } else {
        $list = $cachedList;
    }

    $totalFlagged = count($list);
    if ($totalFlagged === 0) {
        return [
            'connected' => true,
            'email' => null,
            'totalFlagged' => 0,
        ];
    }

    $pick = $list[array_rand($list)];

    return [
        'connected' => true,
        'email' => $pick,
        'totalFlagged' => $totalFlagged,
    ];
}

/**
 * Get the total count of flagged emails in inbox (for count tile).
 */
function getFlaggedEmailCountData(int $userId): array
{
    $token = getOAuthToken($userId, 'microsoft');

    if (!$token) {
        return ['connected' => false];
    }

    $cacheKey = "flagged_email_count_{$userId}";
    $ttl = config('refresh.email', 300);
    $cached = cache($cacheKey);

    if ($cached !== null) {
        return $cached;
    }

    try {
        $list = fetchFlaggedEmailsList($token);
        $totalFlagged = count($list);

        $result = [
            'connected' => true,
            'count' => $totalFlagged,
        ];

        cache($cacheKey, fn() => $result, $ttl);
        return $result;
    } catch (Exception $e) {
        logMessage('Flagged email count fetch error: ' . $e->getMessage(), 'error');
        return ['connected' => true, 'error' => 'Failed to fetch flagged email count'];
    }
}

/**
 * Get the count of incomplete Microsoft To Do tasks that are overdue (due date in the past).
 * Uses same cache TTL as todo; counts across all To Do lists.
 */
function getOverdueTasksCountData(int $userId): array
{
    $token = getOAuthToken($userId, 'microsoft');

    if (!$token) {
        return ['connected' => false];
    }

    $cacheKey = "overdue_tasks_count_{$userId}";
    $cached = cache($cacheKey);

    if ($cached !== null) {
        return $cached;
    }

    $now = gmdate('Y-m-d\TH:i:s\Z');
    $count = 0;

    try {
        $listsResponse = callMicrosoftGraph($token, '/me/todo/lists', ['$top' => 50]);
        $lists = $listsResponse['value'] ?? [];

        foreach ($lists as $list) {
            $listId = $list['id'];
            try {
                $tasksResponse = callMicrosoftGraph($token, "/me/todo/lists/{$listId}/tasks", [
                    '$top' => 500,
                    '$select' => 'id,status,dueDateTime',
                ]);
            } catch (Exception $e) {
                logMessage("Overdue count: list {$listId} fetch failed: " . $e->getMessage(), 'info');
                continue;
            }

            foreach ($tasksResponse['value'] ?? [] as $task) {
                if (($task['status'] ?? '') === 'completed') {
                    continue;
                }
                $dueStr = $task['dueDateTime']['dateTime'] ?? null;
                if ($dueStr === null) {
                    continue;
                }
                if (strcmp($dueStr, $now) < 0) {
                    $count++;
                }
            }
        }

        $result = ['connected' => true, 'count' => $count];
        $ttl = config('refresh.todo', 300);
        cache($cacheKey, fn() => $result, $ttl);
        return $result;
    } catch (Exception $e) {
        logMessage('Overdue tasks count fetch error: ' . $e->getMessage(), 'error');
        return ['connected' => true, 'error' => 'Failed to fetch overdue tasks count'];
    }
}

/**
 * Fetch list of flagged messages from inbox (minimal fields for reminder tile).
 *
 * @return array<int, array{id: string, subject: string, from: string, receivedDateTime: string, receivedTime: string, preview: string, webLink: string}>
 */
function fetchFlaggedEmailsList(string $token): array
{
    $select = 'id,subject,from,receivedDateTime,bodyPreview,webLink';
    $list = [];

    try {
        // Try filtered request first (supported in Graph for flag/flagStatus)
        $response = callMicrosoftGraph($token, '/me/mailFolders/inbox/messages', [
            '$filter' => "flag/flagStatus eq 'flagged'",
            '$top' => 500,
            '$orderby' => 'receivedDateTime desc',
            '$select' => $select,
        ]);
    } catch (Exception $e) {
        // Fallback: fetch recent messages and filter in PHP (e.g. if filter not supported)
        logMessage('Flagged filter fallback: ' . $e->getMessage(), 'info');
        $response = callMicrosoftGraph($token, '/me/mailFolders/inbox/messages', [
            '$top' => 500,
            '$orderby' => 'receivedDateTime desc',
            '$select' => $select . ',flag',
        ]);
        $raw = $response['value'] ?? [];
        $response['value'] = array_filter($raw, function ($m) {
            return ($m['flag']['flagStatus'] ?? '') === 'flagged';
        });
    }

    foreach ($response['value'] ?? [] as $email) {
        $rawPreview = $email['bodyPreview'] ?? '';
        $rawPreview = html_entity_decode($rawPreview, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $list[] = [
            'id' => $email['id'],
            'subject' => $email['subject'] ?? '(No Subject)',
            'from' => $email['from']['emailAddress']['name'] ?? $email['from']['emailAddress']['address'] ?? 'Unknown',
            'receivedDateTime' => $email['receivedDateTime'] ?? '',
            'receivedTime' => formatRelativeTime($email['receivedDateTime'] ?? ''),
            'preview' => truncate($rawPreview, 200),
            'webLink' => $email['webLink'] ?? '',
        ];
    }

    return $list;
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
            '$select' => 'subject,start,end,location,isAllDay,responseStatus',
            '$orderby' => 'start/dateTime',
            '$top' => 10
        ]);

        $events = [];
        foreach ($response['value'] ?? [] as $event) {
            // Skip declined events
            $responseStatus = null;
            if (isset($event['responseStatus']) && is_array($event['responseStatus'])) {
                $responseStatus = $event['responseStatus']['response'] ?? null;
            }
            if ($responseStatus === 'declined') {
                continue;
            }

            // Handle all-day events which may not have dateTime
            if (!isset($event['start']['dateTime']) || !isset($event['end']['dateTime'])) {
                continue; // Skip events without dateTime (shouldn't happen for calendarView, but safety check)
            }

            try {
                $startTime = new DateTime($event['start']['dateTime'], new DateTimeZone($event['start']['timeZone'] ?? 'UTC'));
                $startTime->setTimezone(new DateTimeZone($timezone));
                
                $endTime = new DateTime($event['end']['dateTime'], new DateTimeZone($event['end']['timeZone'] ?? 'UTC'));
                $endTime->setTimezone(new DateTimeZone($timezone));
            } catch (Exception $e) {
                // Skip events with invalid date/time
                logMessage('Calendar event date parsing error: ' . $e->getMessage(), 'warning');
                continue;
            }

            $events[] = [
                'id' => $event['id'],
                'subject' => $event['subject'] ?? '(No Title)',
                'startTime' => $event['isAllDay'] ? 'All Day' : $startTime->format('g:i A'),
                'startDateTime' => $startTime->format('c'), // ISO 8601 format for JavaScript
                'endDateTime' => $endTime->format('c'), // ISO 8601 format for JavaScript
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
 * Get calendar heatmap data: event counts per weekday for current week + next 4 weeks (Mon–Fri only).
 * Returns days in row order (5 rows × 5 weekdays) for heat map display.
 */
function getCalendarHeatmapData(int $userId): array
{
    $token = getOAuthToken($userId, 'microsoft');

    if (!$token) {
        return ['connected' => false];
    }

    $cacheKey = "calendar_heatmap_v2_{$userId}";
    $cached = cache($cacheKey);

    if ($cached !== null) {
        return $cached;
    }

    try {
        $timezone = config('app.timezone', 'UTC');
        $tz = new DateTimeZone($timezone);
        $today = new DateTime('today', $tz);
        $monday = (clone $today)->modify('Monday this week');
        $start = (clone $monday)->setTime(0, 0, 0);
        $endFriday = (clone $monday)->modify('+4 weeks')->modify('Friday this week');
        $end = (clone $endFriday)->setTime(23, 59, 59);

        $response = callMicrosoftGraph($token, '/me/calendarView', [
            'startDateTime' => $start->format('c'),
            'endDateTime' => $end->format('c'),
            '$select' => 'subject,start,end,isAllDay,responseStatus',
            '$orderby' => 'start/dateTime',
            '$top' => 500
        ]);

        $eventsByDate = [];
        foreach ($response['value'] ?? [] as $event) {
            // Skip declined events
            $responseStatus = null;
            if (isset($event['responseStatus'])) {
                if (is_array($event['responseStatus'])) {
                    $responseStatus = $event['responseStatus']['response'] ?? null;
                } else {
                    $responseStatus = $event['responseStatus'];
                }
            }
            if ($responseStatus === 'declined') {
                continue;
            }

            $startRaw = $event['start']['dateTime'] ?? $event['start']['date'] ?? null;
            if (!$startRaw) {
                continue;
            }
            $startDt = new DateTime($startRaw, new DateTimeZone($event['start']['timeZone'] ?? 'UTC'));
            $startDt->setTimezone($tz);
            $dow = (int) $startDt->format('w');
            if ($dow === 0 || $dow === 6) {
                continue;
            }
            $dateKey = $startDt->format('Y-m-d');
            if (!isset($eventsByDate[$dateKey])) {
                $eventsByDate[$dateKey] = [];
            }
            $startTime = ($event['isAllDay'] ?? false) ? 'All day' : $startDt->format('g:i A');
            $eventsByDate[$dateKey][] = [
                'subject' => $event['subject'] ?? '(No Title)',
                'startTime' => $startTime,
                'isAllDay' => $event['isAllDay'] ?? false,
            ];
        }

        $days = [];
        $cur = clone $monday;
        for ($w = 0; $w < 5; $w++) {
            for ($d = 0; $d < 5; $d++) {
                $dateKey = $cur->format('Y-m-d');
                $dayEvents = $eventsByDate[$dateKey] ?? [];
                $days[] = [
                    'date' => $dateKey,
                    'day' => (int) $cur->format('j'),
                    'count' => count($dayEvents),
                    'events' => $dayEvents
                ];
                $cur->modify('+1 day');
            }
            $cur->modify('+2 days'); /* after Fri we're at Sat; +2 => Mon */
        }

        $maxCount = $days ? max(array_column($days, 'count')) : 0;
        $result = [
            'connected' => true,
            'days' => $days,
            'maxCount' => $maxCount
        ];

        cache($cacheKey, fn() => $result, config('refresh.calendar', 600));

        return $result;
    } catch (Exception $e) {
        logMessage('Calendar heatmap fetch error: ' . $e->getMessage(), 'error');
        return ['connected' => true, 'error' => 'Failed to fetch calendar', 'days' => [], 'maxCount' => 0];
    }
}

/**
 * Get the next upcoming calendar event (any calendar, no category filter) for next-event tile.
 * Uses calendarView so recurring events are expanded into actual occurrences (true next event).
 */
function getNextCalendarEvent(int $userId, int $tileId): array
{
    $token = getOAuthToken($userId, 'microsoft');
    if (!$token) {
        return ['connected' => false];
    }

    $cacheKey = "calendar_next_event_{$userId}_{$tileId}";
    $cached = cache($cacheKey);
    if ($cached !== null) {
        return $cached;
    }

    try {
        $timezone = config('app.timezone', 'UTC');
        $tz = new DateTimeZone($timezone);
        $now = new DateTime('now', $tz);
        $end = (clone $now)->modify('+1 year');
        $startDateTime = $now->format('c');
        $endDateTime = $end->format('c');

        // calendarView expands recurring events into instances; /me/events would return series masters only
        // Fetch multiple events to find the first non-declined one
        $response = callMicrosoftGraph($token, '/me/calendarView', [
            'startDateTime' => $startDateTime,
            'endDateTime' => $endDateTime,
            '$select' => 'subject,start,end,location,isAllDay,bodyPreview,responseStatus',
            '$orderby' => 'start/dateTime',
            '$top' => 10,
        ]);

        $value = $response['value'] ?? [];
        $event = null;

        // Find the first non-declined event
        foreach ($value as $ev) {
            $responseStatus = $ev['responseStatus']['response'] ?? 'notResponded';
            if ($responseStatus === 'declined') {
                continue; // Skip declined events
            }

            $startTime = new DateTime($ev['start']['dateTime'], new DateTimeZone($ev['start']['timeZone'] ?? 'UTC'));
            $startTime->setTimezone($tz);
            $endTime = new DateTime($ev['end']['dateTime'], new DateTimeZone($ev['end']['timeZone'] ?? 'UTC'));
            $endTime->setTimezone($tz);
            
            $event = [
                'id' => $ev['id'],
                'subject' => $ev['subject'] ?? '(No Title)',
                'startTime' => $ev['isAllDay'] ? 'All Day' : $startTime->format('g:i A'),
                'startDate' => $startTime->format('l, F j, Y'),
                'startDateTime' => $ev['start']['dateTime'] ?? '',
                'endDateTime' => $ev['end']['dateTime'] ?? '',
                'location' => $ev['location']['displayName'] ?? null,
                'isAllDay' => $ev['isAllDay'] ?? false,
                'bodyPreview' => $ev['bodyPreview'] ?? null,
            ];
            break; // Found first non-declined event
        }

        $result = [
            'connected' => true,
            'event' => $event,
        ];
        cache($cacheKey, fn() => $result, config('refresh.calendar', 600));
        return $result;
    } catch (Exception $e) {
        logMessage('Next-event fetch error: ' . $e->getMessage(), 'error');
        return ['connected' => true, 'event' => null, 'error' => 'Failed to fetch next event'];
    }
}

/**
 * Get next calendar event in a given Outlook category (for calendar-next tile)
 */
function getNextEventByCategory(int $userId, int $tileId): array
{
    $token = getOAuthToken($userId, 'microsoft');
    if (!$token) {
        return ['connected' => false];
    }

    $tile = Database::queryOne('SELECT settings FROM tiles WHERE id = ? AND user_id = ?', [$tileId, $userId]);
    if (!$tile) {
        return ['connected' => true, 'configured' => false, 'event' => null];
    }
    $settings = json_decode($tile['settings'] ?? '{}', true);
    if (!is_array($settings)) {
        $settings = [];
    }
    $category = trim($settings['category'] ?? '');
    if ($category === '') {
        return ['connected' => true, 'configured' => false, 'event' => null];
    }

    $cacheKey = "calendar_next_{$userId}_{$tileId}";
    $cached = cache($cacheKey);
    if ($cached !== null) {
        return $cached;
    }

    try {
        $timezone = config('app.timezone', 'UTC');
        $nowUtc = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        $categoryEscaped = str_replace("'", "''", $category);
        $filter = "categories/any(x:x eq '{$categoryEscaped}') and start/dateTime ge '{$nowUtc}'";

        $response = callMicrosoftGraph($token, '/me/events', [
            '$filter' => $filter,
            '$orderby' => 'start/dateTime',
            '$top' => 2,
            '$select' => 'subject,start,end,location,isAllDay,bodyPreview',
        ]);

        $value = $response['value'] ?? [];
        $event = null;
        $eventNext = null;

        $mapEvent = function (array $ev) use ($timezone): array {
            $startTime = new DateTime($ev['start']['dateTime'], new DateTimeZone($ev['start']['timeZone'] ?? 'UTC'));
            $startTime->setTimezone(new DateTimeZone($timezone));
            return [
                'id' => $ev['id'],
                'subject' => $ev['subject'] ?? '(No Title)',
                'startTime' => $ev['isAllDay'] ? 'All Day' : $startTime->format('g:i A'),
                'startDate' => $startTime->format('l, F j, Y'),
                'startDateTime' => $ev['start']['dateTime'] ?? '',
                'location' => $ev['location']['displayName'] ?? null,
                'isAllDay' => $ev['isAllDay'] ?? false,
                'bodyPreview' => $ev['bodyPreview'] ?? null,
            ];
        };

        if (!empty($value)) {
            $event = $mapEvent($value[0]);
            if (isset($value[1])) {
                $eventNext = $mapEvent($value[1]);
            }
        }

        $result = [
            'connected' => true,
            'configured' => true,
            'category' => $category,
            'event' => $event,
            'eventNext' => $eventNext,
        ];
        cache($cacheKey, fn() => $result, config('refresh.calendar', 600));
        return $result;
    } catch (Exception $e) {
        logMessage('Calendar-next fetch error: ' . $e->getMessage(), 'error');
        return ['connected' => true, 'configured' => true, 'category' => $category, 'event' => null, 'error' => 'Failed to fetch next event'];
    }
}

/**
 * Get todo/tasks data from Microsoft Graph API
 *
 * Which tasks are included is controlled by config('microsoft.todo_show'):
 * - 'all'     = all incomplete tasks from the default To Do list (single list). Reliable.
 * - 'my_day'  = Planner "My Day" tasks via GET /me/planner/myDayTasks (beta). If Planner is not
 *               available, falls back to To Do all incomplete with a note.
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
    $tasksSource = 'all_incomplete';
    $tasksSourceLabelMyDayUnsupported = null;
    $tasksListId = null;  // set when tasks are from To Do (needed for PATCH complete)
    $tasks = [];

    try {
        // My Day: combine Planner My Day tasks + To Do My Day tasks
        if ($todoShow === 'my_day') {
            $plannerAvailable = false;
            $todoTotalCount = 0;
            $todoHasIsInMyDayProperty = false;
            // Fetch Planner My Day tasks
            try {
                $plannerResp = callMicrosoftGraphBeta($token, '/me/planner/myDayTasks', []);
                $plannerTasks = $plannerResp['value'] ?? [];
                foreach ($plannerTasks as $pt) {
                    $pct = (int) ($pt['percentComplete'] ?? 0);
                    if ($pct >= 100) {
                        continue;
                    }
                    $dueDateTime = null;
                    if (!empty($pt['dueDateTime'])) {
                        $dueDateTime = is_string($pt['dueDateTime']) ? $pt['dueDateTime'] : ($pt['dueDateTime']['dateTime'] ?? null);
                    }
                    $tasks[] = [
                        'id' => $pt['id'],
                        'title' => $pt['title'] ?? '(No Title)',
                        'importance' => 'normal',
                        'dueDate' => $dueDateTime ? formatDate($dueDateTime, 'M j, Y') : null,
                        'dueDateTime' => $dueDateTime,
                        'completed' => false,
                        'source' => 'planner',  // Track source for completion API
                    ];
                }
                $plannerAvailable = true;
            } catch (Exception $e) {
                logMessage('Planner My Day not available: ' . $e->getMessage(), 'info');
            }

            // Also fetch To Do My Day tasks from all lists
            $todoMyDayCount = 0;
            try {
                $listsResponse = callMicrosoftGraph($token, '/me/todo/lists', ['$top' => 50]);
                if (!empty($listsResponse['value'])) {
                    $todoMyDaySelect = 'id,title,status,importance,dueDateTime,isInMyDay';
                    foreach ($listsResponse['value'] as $list) {
                        $listId = $list['id'];
                        $todoResp = null;
                        try {
                            $todoResp = callMicrosoftGraphBeta($token, "/me/todo/lists/{$listId}/tasks", [
                                '$top' => 100,
                                '$select' => $todoMyDaySelect,
                            ]);
                        } catch (Exception $e) {
                            // If $select=isInMyDay fails, retry without it
                            if (stripos($e->getMessage(), 'isInMyDay') !== false || stripos($e->getMessage(), 'property') !== false) {
                                logMessage("To Do list {$listId}: isInMyDay property not available, fetching without $select", 'info');
                                try {
                                    $todoResp = callMicrosoftGraphBeta($token, "/me/todo/lists/{$listId}/tasks", ['$top' => 100]);
                                } catch (Exception $e2) {
                                    logMessage("To Do list {$listId}: fetch failed: " . $e2->getMessage(), 'info');
                                    continue;
                                }
                            } else {
                                logMessage("To Do list {$listId}: fetch failed: " . $e->getMessage(), 'info');
                                continue;
                            }
                        }
                        foreach ($todoResp['value'] ?? [] as $task) {
                            if (($task['status'] ?? '') === 'completed') {
                                continue;
                            }
                            $todoTotalCount++;
                            if (array_key_exists('isInMyDay', $task)) {
                                $todoHasIsInMyDayProperty = true;
                                if (!($task['isInMyDay'] ?? false)) {
                                    continue;  // Skip tasks not in My Day
                                }
                                $todoMyDayCount++;
                            } else {
                                // If isInMyDay not in response, log first task's available properties for debugging
                                if ($todoTotalCount === 1 && !$todoHasIsInMyDayProperty) {
                                    $sampleKeys = array_keys($task);
                                    logMessage("To Do task sample properties: " . implode(', ', $sampleKeys), 'info');
                                }
                                // If isInMyDay not in response, skip this task (can't verify it's in My Day)
                                continue;
                            }
                            $dueDateTime = !empty($task['dueDateTime']['dateTime']) ? $task['dueDateTime']['dateTime'] : null;
                            $tasks[] = [
                                'id' => $task['id'],
                                'title' => $task['title'] ?? '(No Title)',
                                'importance' => $task['importance'] ?? 'normal',
                                'dueDate' => $dueDateTime ? formatDate($dueDateTime, 'M j, Y') : null,
                                'dueDateTime' => $dueDateTime,
                                'completed' => false,
                                'source' => 'todo',  // Track source for completion API
                                'list_id' => $listId,  // Needed for To Do completion
                            ];
                            // Track first list ID for fallback (if we need to show all incomplete)
                            if ($tasksListId === null) {
                                $tasksListId = $listId;
                            }
                        }
                    }
                    if ($todoTotalCount > 0 && !$todoHasIsInMyDayProperty) {
                        logMessage("To Do My Day: Found {$todoTotalCount} incomplete To Do tasks, but isInMyDay property not available in API response", 'info');
                    } elseif ($todoHasIsInMyDayProperty && $todoMyDayCount === 0) {
                        logMessage("To Do My Day: Found {$todoTotalCount} incomplete To Do tasks, but none have isInMyDay=true", 'info');
                    } elseif ($todoMyDayCount > 0) {
                        logMessage("To Do My Day: Found {$todoMyDayCount} tasks with isInMyDay=true out of {$todoTotalCount} incomplete tasks", 'info');
                    }
                }
            } catch (Exception $e) {
                logMessage('To Do My Day fetch error: ' . $e->getMessage(), 'info');
            }

            if (!empty($tasks)) {
                $tasksSource = 'my_day';
                $tasksSourceLabelMyDayUnsupported = null;
            } else {
                // No My Day tasks found, fall back to all incomplete
                if ($plannerAvailable) {
                    $tasksSourceLabelMyDayUnsupported = 'All incomplete (no My Day tasks)';
                } else {
                    $tasksSourceLabelMyDayUnsupported = 'All incomplete (My Day not available)';
                }
                $todoShow = 'all';
            }
        }

        // To Do path: when not My Day, or when My Day fell back (Planner failed)
        if ($tasksSource !== 'my_day') {
            $listsResponse = callMicrosoftGraph($token, '/me/todo/lists', ['$top' => 50]);

            if (empty($listsResponse['value'])) {
                if ($tasksSourceLabelMyDayUnsupported !== null) {
                    // Keep the fallback label; tasks may already be empty
                } else {
                    return ['connected' => true, 'tasks' => [], 'tasks_source' => $tasksSource, 'tasks_source_label' => 'No lists'];
                }
            } else {
                $firstListId = $listsResponse['value'][0]['id'];
                $tasksListId = $firstListId;
                try {
                    $tasksResponse = callMicrosoftGraphBeta($token, "/me/todo/lists/{$firstListId}/tasks", ['$top' => 100]);
                } catch (Exception $e) {
                    $tasksResponse = callMicrosoftGraph($token, "/me/todo/lists/{$firstListId}/tasks", ['$top' => 50]);
                }
                foreach ($tasksResponse['value'] ?? [] as $task) {
                    if (($task['status'] ?? '') === 'completed') {
                        continue;
                    }
                    $dueDateTime = !empty($task['dueDateTime']['dateTime']) ? $task['dueDateTime']['dateTime'] : null;
                    $tasks[] = [
                        'id' => $task['id'],
                        'title' => $task['title'] ?? '(No Title)',
                        'importance' => $task['importance'] ?? 'normal',
                        'dueDate' => $dueDateTime ? formatDate($dueDateTime, 'M j, Y') : null,
                        'dueDateTime' => $dueDateTime,
                        'completed' => false,
                    ];
                }
            }
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

        if ($tasksSource === 'my_day') {
            $plannerCount = count(array_filter($tasks, fn($t) => ($t['source'] ?? '') === 'planner'));
            $todoCount = count(array_filter($tasks, fn($t) => ($t['source'] ?? '') === 'todo'));
            if ($plannerCount > 0 && $todoCount > 0) {
                $tasksSourceLabel = 'My Day (Planner + To Do)';
            } elseif ($plannerCount > 0) {
                // Only Planner tasks - To Do My Day likely not available
                if ($todoTotalCount > 0 && !$todoHasIsInMyDayProperty) {
                    $tasksSourceLabel = 'My Day (Planner only; To Do My Day not available)';
                } else {
                    $tasksSourceLabel = 'My Day (Planner)';
                }
            } elseif ($todoCount > 0) {
                $tasksSourceLabel = 'My Day (To Do)';
            } else {
                $tasksSourceLabel = 'My Day';
            }
        } else {
            $tasksSourceLabel = $tasksSourceLabelMyDayUnsupported ?? 'All incomplete (default list)';
        }

        $result = [
            'connected' => true,
            'tasks' => $tasks,
            'tasks_source' => $tasksSource,
            'tasks_source_label' => $tasksSourceLabel,
        ];
        if ($tasksListId !== null) {
            $result['tasks_list_id'] = $tasksListId;
        }

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
 * Get personal To Do tasks - attempts My Day filtering if available, otherwise shows all incomplete
 */
function getTodoPersonalData(int $userId): array
{
    $token = getOAuthToken($userId, 'microsoft');

    if (!$token) {
        return ['connected' => false];
    }

    $cacheKey = "todo_personal_{$userId}";
    $cached = cache($cacheKey);

    if ($cached !== null) {
        return $cached;
    }

    $tasksSource = 'all_incomplete';
    $tasksListId = null;
    $tasks = [];
    $todoHasIsInMyDayProperty = false;
    $todoMyDayCount = 0;
    $todoTotalCount = 0;

    try {
        $listsResponse = callMicrosoftGraph($token, '/me/todo/lists', ['$top' => 50]);

        if (empty($listsResponse['value'])) {
            return ['connected' => true, 'tasks' => [], 'tasks_source' => $tasksSource, 'tasks_source_label' => 'No lists'];
        }

        $firstListId = $listsResponse['value'][0]['id'];
        $tasksListId = $firstListId;
        
        // Try to fetch with isInMyDay property for My Day filtering
        $todoMyDaySelect = 'id,title,status,importance,dueDateTime,isInMyDay';
        $tasksResponse = null;
        try {
            $tasksResponse = callMicrosoftGraphBeta($token, "/me/todo/lists/{$firstListId}/tasks", [
                '$top' => 100,
                '$select' => $todoMyDaySelect,
            ]);
        } catch (Exception $e) {
            // If $select=isInMyDay fails, retry without it
            if (stripos($e->getMessage(), 'isInMyDay') !== false || stripos($e->getMessage(), 'property') !== false) {
                try {
                    $tasksResponse = callMicrosoftGraphBeta($token, "/me/todo/lists/{$firstListId}/tasks", ['$top' => 100]);
                } catch (Exception $e2) {
                    $tasksResponse = callMicrosoftGraph($token, "/me/todo/lists/{$firstListId}/tasks", ['$top' => 50]);
                }
            } else {
                $tasksResponse = callMicrosoftGraph($token, "/me/todo/lists/{$firstListId}/tasks", ['$top' => 50]);
            }
        }
        
        foreach ($tasksResponse['value'] ?? [] as $task) {
            if (($task['status'] ?? '') === 'completed') {
                continue;
            }
            $todoTotalCount++;
            
            // Try to filter by My Day if property is available
            if (array_key_exists('isInMyDay', $task)) {
                $todoHasIsInMyDayProperty = true;
                if (!($task['isInMyDay'] ?? false)) {
                    continue;  // Skip tasks not in My Day
                }
                $todoMyDayCount++;
                $tasksSource = 'my_day';
            }
            
            $dueDateTime = !empty($task['dueDateTime']['dateTime']) ? $task['dueDateTime']['dateTime'] : null;
            $tasks[] = [
                'id' => $task['id'],
                'title' => $task['title'] ?? '(No Title)',
                'importance' => $task['importance'] ?? 'normal',
                'dueDate' => $dueDateTime ? formatDate($dueDateTime, 'M j, Y') : null,
                'dueDateTime' => $dueDateTime,
                'completed' => false,
                'source' => 'todo',
                'list_id' => $firstListId,
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

        // Set label based on whether My Day filtering worked
        if ($tasksSource === 'my_day' && $todoMyDayCount > 0) {
            $tasksSourceLabel = 'Personal To Do (My Day)';
        } elseif ($todoTotalCount > 0 && !$todoHasIsInMyDayProperty) {
            $tasksSourceLabel = 'Personal To Do (My Day not available)';
        } else {
            $tasksSourceLabel = 'Personal To Do';
        }

        $result = [
            'connected' => true,
            'tasks' => $tasks,
            'tasks_source' => $tasksSource,
            'tasks_source_label' => $tasksSourceLabel,
            'tasks_list_id' => $tasksListId,
        ];

        cache($cacheKey, fn() => $result, config('refresh.todo', 300));

        return $result;
    } catch (Exception $e) {
        logMessage('Personal To Do fetch error: ' . $e->getMessage(), 'error');
        return [
            'connected' => true,
            'error' => 'Failed to fetch tasks: ' . $e->getMessage(),
        ];
    }
}

/**
 * Get Planner overview: outstanding tasks assigned to the user, grouped by plan.
 * Uses GET /me/planner/tasks and GET /planner/plans/{id} for plan titles.
 */
function getPlannerOverviewData(int $userId): array
{
    $token = getOAuthToken($userId, 'microsoft');

    if (!$token) {
        return ['connected' => false];
    }

    $cacheKey = "planner_overview_v2_{$userId}";
    $cached = cache($cacheKey);

    if ($cached !== null) {
        return $cached;
    }

    try {
        $colCheck = Database::query("SHOW COLUMNS FROM users LIKE 'planner_overview_max_plans'");
        if (empty($colCheck)) {
            Database::execute('ALTER TABLE users ADD COLUMN planner_overview_max_plans TINYINT UNSIGNED DEFAULT NULL');
            Database::execute('ALTER TABLE users ADD COLUMN planner_overview_max_tasks_per_plan TINYINT UNSIGNED DEFAULT NULL');
        }
    } catch (Exception $e) {
        /* ignore migration errors */
    }
    $userPrefs = Database::queryOne(
        'SELECT planner_overview_max_plans, planner_overview_max_tasks_per_plan FROM users WHERE id = ?',
        [$userId]
    );
    $maxPlans = (int) ($userPrefs['planner_overview_max_plans'] ?? config('planner_overview.max_plans', 10));
    $maxTasksPerPlan = (int) ($userPrefs['planner_overview_max_tasks_per_plan'] ?? config('planner_overview.max_tasks_per_plan', 15));
    $maxPlans = max(1, min(50, $maxPlans));
    $maxTasksPerPlan = max(1, min(100, $maxTasksPerPlan));

    try {
        $response = callMicrosoftGraph($token, '/me/planner/tasks', []);
        $rawTasks = $response['value'] ?? [];
    } catch (Exception $e) {
        $msg = $e->getMessage();
        if (stripos($msg, '403') !== false || stripos($msg, 'Forbidden') !== false || stripos($msg, 'not supported') !== false) {
            logMessage('Planner overview: Planner not available (' . $msg . ')', 'info');
            return ['connected' => true, 'plans' => [], 'planner_unavailable' => true];
        }
        logMessage('Planner overview fetch error: ' . $msg, 'error');
        return ['connected' => true, 'plans' => [], 'error' => 'Failed to fetch Planner tasks'];
    }

    $incomplete = [];
    foreach ($rawTasks as $pt) {
        $pct = (int) ($pt['percentComplete'] ?? 0);
        if ($pct >= 100) {
            continue;
        }
        $incomplete[] = $pt;
    }

    $total_incomplete = count($incomplete);

    $allPlanIds = array_values(array_unique(array_filter(array_column($incomplete, 'planId'))));
    $planIds = array_slice($allPlanIds, 0, $maxPlans);
    $hiddenPlanIds = array_slice($allPlanIds, $maxPlans);

    $planTitles = [];
    foreach ($planIds as $planId) {
        try {
            $plan = callMicrosoftGraph($token, '/planner/plans/' . $planId, []);
            $planTitles[$planId] = $plan['title'] ?? ('Plan ' . substr($planId, 0, 8));
        } catch (Exception $e) {
            logMessage('Planner overview: could not fetch plan ' . $planId . ': ' . $e->getMessage(), 'info');
            $planTitles[$planId] = 'Plan ' . substr($planId, 0, 8);
        }
    }

    $hidden_plans = [];
    foreach ($hiddenPlanIds as $planId) {
        try {
            $plan = callMicrosoftGraph($token, '/planner/plans/' . $planId, []);
            $hidden_plans[] = ['id' => $planId, 'title' => $plan['title'] ?? ('Plan ' . substr($planId, 0, 8))];
        } catch (Exception $e) {
            logMessage('Planner overview: could not fetch hidden plan ' . $planId . ': ' . $e->getMessage(), 'info');
            $hidden_plans[] = ['id' => $planId, 'title' => 'Plan ' . substr($planId, 0, 8)];
        }
    }

    $byPlan = [];
    foreach ($incomplete as $pt) {
        $planId = $pt['planId'] ?? null;
        if ($planId === null || !isset($planTitles[$planId])) {
            continue;
        }
        if (!isset($byPlan[$planId])) {
            $byPlan[$planId] = ['id' => $planId, 'title' => $planTitles[$planId], 'tasks' => []];
        }
        if (count($byPlan[$planId]['tasks']) >= $maxTasksPerPlan) {
            continue;
        }
        $dueDateTime = $pt['dueDateTime'] ?? null;
        if (is_array($dueDateTime)) {
            $dueDateTime = $dueDateTime['dateTime'] ?? null;
        }
        $priority = (int) ($pt['priority'] ?? 5);
        $importance = ($priority <= 1) ? 'high' : (($priority >= 8) ? 'low' : 'normal');
        $byPlan[$planId]['tasks'][] = [
            'id' => $pt['id'],
            'title' => $pt['title'] ?? '(No Title)',
            'dueDate' => $dueDateTime ? formatDate($dueDateTime, 'M j, Y') : null,
            'dueDateTime' => $dueDateTime,
            'percentComplete' => (int) ($pt['percentComplete'] ?? 0),
            'importance' => $importance,
            'source' => 'planner',
        ];
    }

    foreach ($byPlan as $planId => &$planData) {
        usort($planData['tasks'], function ($a, $b) {
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
    }
    unset($planData);

    $plans = array_values($byPlan);
    usort($plans, function ($a, $b) {
        return strcasecmp($a['title'], $b['title']);
    });

    $result = [
        'connected' => true,
        'plans' => $plans,
        'total_incomplete' => $total_incomplete,
        'hidden_plans' => $hidden_plans,
    ];

    $ttl = config('refresh.planner_overview', 300);
    cache($cacheKey, fn() => $result, $ttl);

    return $result;
}

/**
 * Get a single Planner plan's tasks for "load into tile" (hidden plan).
 * Returns plan id, title, and tasks (same format as in getPlannerOverviewData), capped by user's max_tasks_per_plan.
 */
function getPlannerSinglePlanData(int $userId, string $planId): array
{
    $token = getOAuthToken($userId, 'microsoft');
    if (!$token) {
        return ['connected' => false];
    }

    $userPrefs = Database::queryOne(
        'SELECT planner_overview_max_tasks_per_plan FROM users WHERE id = ?',
        [$userId]
    );
    $maxTasksPerPlan = (int) ($userPrefs['planner_overview_max_tasks_per_plan'] ?? config('planner_overview.max_tasks_per_plan', 15));
    $maxTasksPerPlan = max(1, min(100, $maxTasksPerPlan));

    try {
        $response = callMicrosoftGraph($token, '/me/planner/tasks', []);
        $rawTasks = $response['value'] ?? [];
    } catch (Exception $e) {
        logMessage('Planner single plan fetch error: ' . $e->getMessage(), 'error');
        return ['connected' => true, 'error' => 'Failed to fetch tasks'];
    }

    $planTitle = 'Plan ' . substr($planId, 0, 8);
    try {
        $plan = callMicrosoftGraph($token, '/planner/plans/' . $planId, []);
        $planTitle = $plan['title'] ?? $planTitle;
    } catch (Exception $e) {
        /* use fallback title */
    }

    $tasks = [];
    foreach ($rawTasks as $pt) {
        if (($pt['planId'] ?? '') !== $planId) {
            continue;
        }
        $pct = (int) ($pt['percentComplete'] ?? 0);
        if ($pct >= 100) {
            continue;
        }
        if (count($tasks) >= $maxTasksPerPlan) {
            break;
        }
        $dueDateTime = $pt['dueDateTime'] ?? null;
        if (is_array($dueDateTime)) {
            $dueDateTime = $dueDateTime['dateTime'] ?? null;
        }
        $priority = (int) ($pt['priority'] ?? 5);
        $importance = ($priority <= 1) ? 'high' : (($priority >= 8) ? 'low' : 'normal');
        $tasks[] = [
            'id' => $pt['id'],
            'title' => $pt['title'] ?? '(No Title)',
            'dueDate' => $dueDateTime ? formatDate($dueDateTime, 'M j, Y') : null,
            'dueDateTime' => $dueDateTime,
            'percentComplete' => (int) ($pt['percentComplete'] ?? 0),
            'importance' => $importance,
            'source' => 'planner',
        ];
    }

    usort($tasks, function ($a, $b) {
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

    return [
        'connected' => true,
        'plan' => [
            'id' => $planId,
            'title' => $planTitle,
            'tasks' => $tasks,
        ],
    ];
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

            $dueDateFull = 'No date set';
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
                $dueDateFull = formatDate($dueDate, 'l, F j, Y');
            }

            // Get contact name from our fetched contacts
            $contactId = $action['contact_id'] ?? '';
            $contactName = $contacts[$contactId] ?? 'Unknown Contact';

            // Status / date flag for overlay (API may return date_flag or status)
            $dateFlag = $action['date_flag'] ?? $action['status'] ?? null;
            $statusLabel = $dateFlag ? ucfirst(str_replace('_', ' ', (string) $dateFlag)) : ($dueDate ? 'Scheduled' : 'ASAP');

            $actions[] = [
                'id' => $action['id'] ?? '',
                'contactName' => $contactName,
                'actionText' => $action['text'] ?? 'Action',
                'dueDate' => $dueDateFormatted,
                'dueDateFull' => $dueDateFull,
                'isOverdue' => $isOverdue,
                'statusLabel' => $statusLabel,
            ];
        }

        // Oldest first so user can prioritise which to tackle first
        $actions = array_reverse($actions);

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
 * Call Microsoft Graph API with PATCH (e.g. update todoTask or plannerTask)
 *
 * @param array $extraHeaders Optional headers, e.g. ['If-Match' => $etag] for Planner
 */
function callMicrosoftGraphPatch(string $token, string $endpoint, array $body, string $version = 'v1.0', array $extraHeaders = []): array
{
    $url = 'https://graph.microsoft.com/' . $version . $endpoint;
    $headers = [
        "Authorization: Bearer {$token}",
        "Content-Type: application/json",
    ];
    foreach ($extraHeaders as $name => $value) {
        $headers[] = "{$name}: {$value}";
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'PATCH',
            'header' => implode("\r\n", $headers),
            'content' => json_encode($body),
            'ignore_errors' => true,
        ]
    ]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        throw new Exception('Failed to call Microsoft Graph API');
    }
    $data = json_decode($response, true);
    if ($data === null && $response !== '' && $response !== 'null') {
        throw new Exception('Invalid response from Microsoft Graph API');
    }
    if (is_array($data) && isset($data['error'])) {
        throw new Exception($data['error']['message'] ?? 'Graph API error');
    }
    return is_array($data) ? $data : [];
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
            'forecast_days' => 3
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
 * Get link board data (categories and items) for the user
 */
function getLinkBoardData(int $userId): array
{
    try {
        $tableCat = Database::queryOne("SHOW TABLES LIKE 'link_board_categories'");
        $tableItems = Database::queryOne("SHOW TABLES LIKE 'link_board_items'");
        if (empty($tableCat) || empty($tableItems)) {
            return ['categories' => [], 'items' => []];
        }
    } catch (Exception $e) {
        return ['categories' => [], 'items' => []];
    }

    $categories = Database::query(
        'SELECT id, name, position FROM link_board_categories WHERE user_id = ? ORDER BY position ASC, id ASC',
        [$userId]
    );
    $items = Database::query(
        'SELECT id, category_id, url, title, summary, position FROM link_board_items WHERE user_id = ? ORDER BY category_id ASC, position ASC, id ASC',
        [$userId]
    );

    return [
        'categories' => array_map(function ($row) {
            return [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'position' => (int) $row['position'],
            ];
        }, $categories),
        'items' => array_map(function ($row) {
            return [
                'id' => (int) $row['id'],
                'category_id' => (int) $row['category_id'],
                'url' => $row['url'],
                'title' => $row['title'],
                'summary' => $row['summary'],
                'position' => (int) $row['position'],
            ];
        }, $items),
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
 * Get availability data: find available meeting slots in the coming fortnight
 */
function getAvailabilityData(int $userId): array
{
    $token = getOAuthToken($userId, 'microsoft');

    if (!$token) {
        return ['connected' => false];
    }

    $cacheKey = "availability_{$userId}";
    $cached = cache($cacheKey);

    if ($cached !== null) {
        return $cached;
    }

    try {
        $timezone = config('app.timezone', 'UTC');
        $tz = new DateTimeZone($timezone);
        $now = new DateTime('now', $tz);
        
        // Start from the beginning of next week (Monday)
        $nextMonday = (clone $now)->modify('Monday next week')->setTime(0, 0, 0);
        
        // End date: 2 weeks from next Monday (fortnight)
        $endDate = (clone $nextMonday)->modify('+2 weeks')->setTime(23, 59, 59);

        // Fetch calendar events for the period.
        // calendarView expands recurring series into instances and returns all events (own + from others).
        // We exclude declined meetings so those times show as available.
        $response = callMicrosoftGraph($token, '/me/calendarView', [
            'startDateTime' => $nextMonday->format('c'),
            'endDateTime' => $endDate->format('c'),
            '$select' => 'subject,start,end,isAllDay,responseStatus',
            '$orderby' => 'start/dateTime',
            '$top' => 500
        ]);

        // Parse events into DateTime objects; skip declined (user is free when they've declined)
        $events = [];
        foreach ($response['value'] ?? [] as $event) {
            // Skip declined meetings – user is available during those times
            $responseStatus = null;
            if (isset($event['responseStatus']) && is_array($event['responseStatus'])) {
                $responseStatus = $event['responseStatus']['response'] ?? null;
            }
            if ($responseStatus === 'declined') {
                continue;
            }

            // Skip all-day events for availability (we only consider timed slots)
            if ($event['isAllDay'] ?? false) {
                continue;
            }

            $startRaw = $event['start']['dateTime'] ?? null;
            $endRaw = $event['end']['dateTime'] ?? null;
            
            if (!$startRaw || !$endRaw) {
                continue;
            }

            try {
                $startTime = new DateTime($startRaw, new DateTimeZone($event['start']['timeZone'] ?? 'UTC'));
                $startTime->setTimezone($tz);
                
                $endTime = new DateTime($endRaw, new DateTimeZone($event['end']['timeZone'] ?? 'UTC'));
                $endTime->setTimezone($tz);

                // Only include events that have valid times (blocks availability)
                if ($startTime && $endTime && $endTime > $startTime) {
                    $events[] = [
                        'start' => $startTime,
                        'end' => $endTime,
                    ];
                }
            } catch (Exception $e) {
                // Skip events with invalid date/time
                logMessage('Availability: Skipping event with invalid date/time: ' . $e->getMessage(), 'info');
                continue;
            }
        }

        // Sort events by start time
        usort($events, function($a, $b) {
            return $a['start'] <=> $b['start'];
        });

        // Find available periods per day (continuous time, split at lunchtime)
        $availableDays = [];
        $bufferHours = 1; // 1 hour buffer between meetings
        
        // Working hours: 9am to 5pm
        $workStartHour = 9;
        $workEndHour = 17;
        
        // Lunch break: 1pm to 2pm
        $lunchStartHour = 13;
        $lunchEndHour = 14;

        // Start checking from next Monday
        $currentDate = clone $nextMonday;
        
        while ($currentDate < $endDate && count($availableDays) < 4) {
            // Skip weekends
            $dayOfWeek = (int)$currentDate->format('w');
            if ($dayOfWeek === 0 || $dayOfWeek === 6) {
                $currentDate->modify('+1 day');
                continue;
            }

            // Get events for this day (events that overlap with the working day)
            $dayStart = (clone $currentDate)->setTime($workStartHour, 0, 0);
            $dayEnd = (clone $currentDate)->setTime($workEndHour, 0, 0);
            $lunchStart = (clone $currentDate)->setTime($lunchStartHour, 0, 0);
            $lunchEnd = (clone $currentDate)->setTime($lunchEndHour, 0, 0);
            
            // Filter events that overlap with this day's working hours
            // Use a more robust check: event overlaps if it intersects with the working day period
            $dayEventsRaw = array_filter($events, function($event) use ($dayStart, $dayEnd) {
                // Event overlaps if: event starts before day ends AND event ends after day starts
                // This handles events that span multiple days or start/end outside working hours
                return $event['start'] < $dayEnd && $event['end'] > $dayStart;
            });
            
            $dayEvents = $dayEventsRaw;

            // Sort events by start time for this day
            usort($dayEvents, function($a, $b) {
                return $a['start'] <=> $b['start'];
            });
            
            // Clip events to working hours and merge overlapping events
            $clippedEvents = [];
            foreach ($dayEvents as $event) {
                $eventStart = $event['start'] > $dayStart ? clone $event['start'] : clone $dayStart;
                $eventEnd = $event['end'] < $dayEnd ? clone $event['end'] : clone $dayEnd;
                
                // Skip if event is invalid after clipping
                if ($eventStart >= $eventEnd) {
                    continue;
                }
                
                // Check if this event overlaps with any existing clipped event
                $merged = false;
                foreach ($clippedEvents as &$clipped) {
                    // If events overlap, merge them
                    if ($eventStart <= $clipped['end'] && $eventEnd >= $clipped['start']) {
                        if ($eventStart < $clipped['start']) {
                            $clipped['start'] = clone $eventStart;
                        }
                        if ($eventEnd > $clipped['end']) {
                            $clipped['end'] = clone $eventEnd;
                        }
                        $merged = true;
                        break;
                    }
                }
                
                if (!$merged) {
                    $clippedEvents[] = [
                        'start' => $eventStart,
                        'end' => $eventEnd,
                    ];
                }
            }
            
            // Re-sort after merging
            usort($clippedEvents, function($a, $b) {
                return $a['start'] <=> $b['start'];
            });
            
            // Add lunch break as a blocked period (1pm-2pm) if not already covered by an event
            $lunchCovered = false;
            foreach ($clippedEvents as $clipped) {
                if ($clipped['start'] <= $lunchStart && $clipped['end'] >= $lunchEnd) {
                    $lunchCovered = true;
                    break;
                }
            }
            
            if (!$lunchCovered) {
                $clippedEvents[] = [
                    'start' => clone $lunchStart,
                    'end' => clone $lunchEnd,
                ];
                
                // Re-sort again to include lunch break
                usort($clippedEvents, function($a, $b) {
                    return $a['start'] <=> $b['start'];
                });
            }
            
            $dayEvents = $clippedEvents;

            // Find all available periods in the day
            // Build a list of blocked periods (events + buffers + lunch)
            $blockedPeriods = [];
            foreach ($dayEvents as $event) {
                $blockedStart = (clone $event['start'])->modify("-{$bufferHours} hours");
                $blockedEnd = (clone $event['end'])->modify("+{$bufferHours} hours");
                
                // Clip to working hours
                if ($blockedStart < $dayStart) {
                    $blockedStart = clone $dayStart;
                }
                if ($blockedEnd > $dayEnd) {
                    $blockedEnd = clone $dayEnd;
                }
                
                if ($blockedStart < $blockedEnd) {
                    $blockedPeriods[] = [
                        'start' => $blockedStart,
                        'end' => $blockedEnd,
                    ];
                }
            }
            
            // Sort blocked periods by start time
            usort($blockedPeriods, function($a, $b) {
                return $a['start'] <=> $b['start'];
            });
            
            // Merge overlapping blocked periods
            $mergedBlocked = [];
            foreach ($blockedPeriods as $blocked) {
                if (empty($mergedBlocked)) {
                    $mergedBlocked[] = $blocked;
                } else {
                    $lastBlocked = &$mergedBlocked[count($mergedBlocked) - 1];
                    if ($blocked['start'] <= $lastBlocked['end']) {
                        // Overlaps or adjacent - merge
                        if ($blocked['end'] > $lastBlocked['end']) {
                            $lastBlocked['end'] = clone $blocked['end'];
                        }
                    } else {
                        $mergedBlocked[] = $blocked;
                    }
                }
            }
            
            // Now find gaps between blocked periods
            $availablePeriods = [];
            $currentTime = clone $dayStart;
            
            foreach ($mergedBlocked as $blocked) {
                // Gap before this blocked period
                if ($currentTime < $blocked['start']) {
                    $availablePeriods[] = [
                        'start' => clone $currentTime,
                        'end' => clone $blocked['start'],
                    ];
                }
                
                // Move current time to after this blocked period
                $currentTime = clone $blocked['end'];
            }
            
            // Gap after last blocked period
            if ($currentTime < $dayEnd) {
                $availablePeriods[] = [
                    'start' => clone $currentTime,
                    'end' => clone $dayEnd,
                ];
            }

            // Merge adjacent periods and calculate total available time
            if (!empty($availablePeriods)) {
                // Sort periods by start time
                usort($availablePeriods, function($a, $b) {
                    return $a['start'] <=> $b['start'];
                });
                
                // Merge adjacent periods (gaps are already correctly calculated, just need to merge if adjacent)
                $mergedPeriods = [];
                foreach ($availablePeriods as $period) {
                    // Ensure period is within working hours (should already be, but double-check)
                    if ($period['start'] < $dayStart || $period['end'] > $dayEnd) {
                        continue;
                    }
                    
                    if (empty($mergedPeriods)) {
                        $mergedPeriods[] = $period;
                    } else {
                        $lastPeriod = &$mergedPeriods[count($mergedPeriods) - 1];
                        // Check if this period is adjacent to the last one (within 1 minute tolerance)
                        $timeDiff = abs($lastPeriod['end']->getTimestamp() - $period['start']->getTimestamp());
                        if ($timeDiff <= 60) { // Adjacent if within 1 minute
                            // Merge periods
                            if ($period['end'] > $lastPeriod['end']) {
                                $lastPeriod['end'] = clone $period['end'];
                            }
                        } else {
                            $mergedPeriods[] = $period;
                        }
                    }
                }
                
                // Calculate total available time (sum of all periods, not span)
                $totalMinutes = 0;
                foreach ($mergedPeriods as $period) {
                    $minutes = ($period['end']->getTimestamp() - $period['start']->getTimestamp()) / 60;
                    $totalMinutes += $minutes;
                }
                
                // Only include days with at least 2 hours of availability
                if ($totalMinutes >= 120) {
                    $availableDays[] = [
                        'date' => clone $currentDate,
                        'periods' => $mergedPeriods,
                        'totalMinutes' => $totalMinutes,
                    ];
                }
            }

            $currentDate->modify('+1 day');
        }

        // Format days for display (each day can have multiple available periods)
        $formattedSlots = [];
        foreach ($availableDays as $day) {
            $dateObj = $day['date'];
            $periods = $day['periods'];
            
            $dayName = $dateObj->format('l');
            $dayNumber = $dateObj->format('j');
            $monthName = $dateObj->format('F');
            $monthAbbr = $dateObj->format('M');
            
            // Build time range string from all periods, e.g. "9am-12pm, 3pm-5pm"
            $timeRangeParts = [];
            $fullTextParts = [];
            
            foreach ($periods as $period) {
                $start = $period['start'];
                $end = $period['end'];
                
                $startTime = $start->format('ga');
                $endTime = $end->format('ga');
                
                if ($endTime === '12pm') {
                    $endTime = 'midday';
                }
                if ($startTime === '12pm') {
                    $startTime = 'midday';
                }
                
                $timeRangeParts[] = "{$startTime}-{$endTime}";
                $fullTextParts[] = "{$dayName} {$dayNumber} {$monthName}, between {$startTime} and {$endTime}";
            }
            
            $timeRange = implode(', ', $timeRangeParts);
            $fullText = implode("\n", $fullTextParts);
            
            // For backwards compatibility, use first period's start/end for startDateTime/endDateTime
            $firstStart = $periods[0]['start'];
            $lastEnd = $periods[count($periods) - 1]['end'];
            
            $formattedSlots[] = [
                'date' => "{$dayName} {$dayNumber} {$monthName}",
                'time' => $timeRange,
                'fullText' => $fullText,
                'startDateTime' => $firstStart->format('c'),
                'endDateTime' => $lastEnd->format('c'),
                'dayNumber' => (int)$dayNumber,
                'monthAbbr' => $monthAbbr,
                'timeRange' => $timeRange,
            ];
        }

        $result = [
            'connected' => true,
            'slots' => $formattedSlots,
            'text' => implode("\n", array_column($formattedSlots, 'fullText')),
        ];

        cache($cacheKey, fn() => $result, config('refresh.calendar', 600));

        return $result;
    } catch (Exception $e) {
        logMessage('Availability fetch error: ' . $e->getMessage(), 'error');
        return ['connected' => true, 'error' => 'Failed to fetch availability', 'slots' => [], 'text' => ''];
    }
}

/**
 * Fetch and parse one direction of train departures (used for both A→B and B→A).
 *
 * @return array{origin: array, destination: array, departures: array}|array{error: string}
 */
function fetchTrainDirection(string $fromCrs, string $toCrs, string $fromName, string $toName, int $numDepartures, array $params, $context, string $accessToken): array
{
    $baseUrl = "https://huxley2.azurewebsites.net/departures/{$fromCrs}/to/{$toCrs}/{$numDepartures}";
    $url = $baseUrl . '?' . http_build_query($params);
    
    $response = @file_get_contents($url, false, $context);
    
    $statusCode = 0;
    if (isset($http_response_header)) {
        $statusLine = $http_response_header[0] ?? '';
        if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $statusLine, $matches)) {
            $statusCode = (int)$matches[1];
        }
    }
    
    if ($response === false) {
        return ['error' => 'Failed to connect to train API'];
    }
    
    if ($statusCode !== 200) {
        $errorData = json_decode($response, true);
        $detail = is_array($errorData) ? ($errorData['error'] ?? $errorData['message'] ?? '') : substr($response, 0, 150);
        return ['error' => "Train API returned status {$statusCode}" . ($detail ? ": {$detail}" : '')];
    }
    
    $data = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE || isset($data['error'])) {
        return ['error' => 'Invalid or error response from train API'];
    }
    
    $departures = [];
    foreach ($data['trainServices'] ?? [] as $service) {
        $scheduledTime = $service['std'] ?? '';
        $expectedTime = $service['etd'] ?? $scheduledTime;
        $platform = $service['platform'] ?? null;
        $operator = $service['operator'] ?? 'Unknown';
        
        $status = 'On time';
        $isDelayed = false;
        $isCancelled = false;
        
        if (isset($service['isCancelled']) && $service['isCancelled']) {
            $status = 'Cancelled';
            $isCancelled = true;
        } elseif ($expectedTime !== $scheduledTime) {
            if ($expectedTime === 'Delayed' || $expectedTime === 'On time') {
                $status = $expectedTime;
                $isDelayed = ($expectedTime === 'Delayed');
            } else {
                $scheduledTimestamp = strtotime($scheduledTime);
                $expectedTimestamp = strtotime($expectedTime);
                if ($expectedTimestamp > $scheduledTimestamp) {
                    $delayMinutes = round(($expectedTimestamp - $scheduledTimestamp) / 60);
                    $status = $delayMinutes > 0 ? "+{$delayMinutes} min" : 'On time';
                    $isDelayed = ($delayMinutes > 0);
                }
            }
        }
        
        $scheduledDisplay = formatTrainTime($scheduledTime);
        $expectedDisplay = ($expectedTime && $expectedTime !== $scheduledTime && !$isCancelled) ? formatTrainTime($expectedTime) : null;
        
        $departures[] = [
            'scheduledTime' => $scheduledTime,
            'scheduledDisplay' => $scheduledDisplay,
            'expectedTime' => $expectedTime,
            'expectedDisplay' => $expectedDisplay,
            'platform' => $platform,
            'operator' => $operator,
            'status' => $status,
            'isDelayed' => $isDelayed,
            'isCancelled' => $isCancelled,
            'destination' => $service['destination'] ?? [],
        ];
    }
    
    return [
        'origin' => ['crs' => $fromCrs, 'name' => $fromName],
        'destination' => ['crs' => $toCrs, 'name' => $toName],
        'departures' => $departures,
    ];
}

/**
 * Get train departure times from National Rail API via Huxley 2
 */
function getTrainDeparturesData(int $userId, int $tileId): array
{
    $tile = Database::queryOne('SELECT settings FROM tiles WHERE id = ? AND user_id = ?', [$tileId, $userId]);
    if (!$tile) {
        return ['configured' => false, 'error' => 'Tile not found'];
    }
    
    $settings = json_decode($tile['settings'] ?? '{}', true);
    if (!is_array($settings)) {
        $settings = [];
    }
    
    $originCrs = trim($settings['origin_crs'] ?? '');
    $destinationCrs = trim($settings['destination_crs'] ?? '');
    $numDepartures = isset($settings['num_departures']) ? (int)$settings['num_departures'] : 5;
    $numDepartures = max(1, min(10, $numDepartures)); // Limit between 1 and 10
    
    if (empty($originCrs) || empty($destinationCrs)) {
        return ['configured' => false, 'error' => 'Station codes not configured'];
    }
    
    // Validate CRS codes (should be 3 uppercase letters)
    if (!preg_match('/^[A-Z]{3}$/', $originCrs) || !preg_match('/^[A-Z]{3}$/', $destinationCrs)) {
        return ['configured' => true, 'error' => 'Invalid station codes'];
    }
    
    $cacheKey = "train_departures_{$userId}_{$tileId}";
    $cached = cache($cacheKey);
    
    if ($cached !== null) {
        return $cached;
    }
    
    $originName = $settings['origin_name'] ?? $originCrs;
    $destinationName = $settings['destination_name'] ?? $destinationCrs;
    
    try {
        $accessToken = config('train.api_token', '');
        $hasToken = !empty($accessToken);
        
        if ($hasToken && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $accessToken)) {
            logMessage("Train API: Token format appears invalid (should be GUID format)", 'warning');
        }
        
        $params = ['expand' => 'true'];
        if ($hasToken) {
            $params['accessToken'] = $accessToken;
        }
        
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'Accept: application/json',
                'ignore_errors' => true,
                'timeout' => 10
            ]
        ]);
        
        // Fetch both directions so the tile can show either without another API call
        $directionAb = fetchTrainDirection($originCrs, $destinationCrs, $originName, $destinationName, $numDepartures, $params, $context, $accessToken);
        $directionBa = fetchTrainDirection($destinationCrs, $originCrs, $destinationName, $originName, $numDepartures, $params, $context, $accessToken);
        
        if (isset($directionAb['error'])) {
            throw new Exception($directionAb['error']);
        }
        if (isset($directionBa['error'])) {
            throw new Exception($directionBa['error']);
        }
        
        // Before midday = show A→B; from midday = show B→A
        $tz = new DateTimeZone(config('app.timezone', 'Europe/London'));
        $hour = (int) (new DateTime('now', $tz))->format('G');
        $defaultDirection = $hour < 12 ? 'ab' : 'ba';
        
        $result = [
            'configured' => true,
            'direction_ab' => $directionAb,
            'direction_ba' => $directionBa,
            'default_direction' => $defaultDirection,
            // Backward compatibility: default view
            'origin' => $defaultDirection === 'ab' ? $directionAb['origin'] : $directionBa['origin'],
            'destination' => $defaultDirection === 'ab' ? $directionAb['destination'] : $directionBa['destination'],
            'departures' => $defaultDirection === 'ab' ? $directionAb['departures'] : $directionBa['departures'],
        ];
        
        cache($cacheKey, fn() => $result, config('refresh.train_departures', 120));
        
        return $result;
    } catch (Exception $e) {
        logMessage('Train departures fetch error: ' . $e->getMessage(), 'error');
        return [
            'configured' => true,
            'error' => 'Failed to fetch train departures: ' . $e->getMessage(),
            'departures' => []
        ];
    }
}

/**
 * Format train time for display (HH:MM format)
 */
function formatTrainTime(string $time): string
{
    if (empty($time) || $time === 'On time' || $time === 'Delayed' || $time === 'Cancelled') {
        return $time;
    }
    
    // Try to parse the time
    $timestamp = strtotime($time);
    if ($timestamp === false) {
        return $time; // Return as-is if parsing fails
    }
    
    return date('H:i', $timestamp);
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
    cacheClear("calendar_heatmap_v2_{$uid}");
    cacheClear("todo_{$uid}");
    cacheClear("planner_overview_v2_{$uid}");
    cacheClear("crm_{$uid}");
    cacheClear("weather_{$uid}");
    cacheClear("flagged_email_{$uid}");
    getEmailData($uid);
    getCalendarData($uid);
    getCalendarHeatmapData($uid);
    getTodoData($uid);
    getPlannerOverviewData($uid);
    getCrmData($uid);
    getWeatherData($uid);
    getFlaggedEmailData($uid);
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'user_id' => $uid]);
    exit;
}
