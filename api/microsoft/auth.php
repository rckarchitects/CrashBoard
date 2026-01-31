<?php
/**
 * Microsoft OAuth2 Authentication Handler
 *
 * Initiates OAuth flow and handles callback.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth.php';

// Require authentication
Auth::require();

$action = get('action', 'connect');
$msConfig = config('microsoft');

if (empty($msConfig['client_id'])) {
    Session::setFlash('error', 'Microsoft 365 is not configured. Please add your Azure AD credentials to the config file.');
    redirect('/settings.php');
}

switch ($action) {
    case 'connect':
        initiateOAuth($msConfig);
        break;
    case 'callback':
        handleCallback($msConfig);
        break;
    case 'disconnect':
        disconnect();
        break;
    default:
        redirect('/settings.php');
}

/**
 * Initiate OAuth flow
 */
function initiateOAuth(array $config): void
{
    // Generate state for CSRF protection
    $state = bin2hex(random_bytes(16));
    Session::set('oauth_state', $state);

    $params = [
        'client_id' => $config['client_id'],
        'response_type' => 'code',
        'redirect_uri' => $config['redirect_uri'],
        'response_mode' => 'query',
        'scope' => implode(' ', $config['scopes']),
        'state' => $state,
        'prompt' => 'consent'
    ];

    $authUrl = 'https://login.microsoftonline.com/' . $config['tenant_id'] . '/oauth2/v2.0/authorize?' . http_build_query($params);

    redirect($authUrl);
}

/**
 * Handle OAuth callback
 */
function handleCallback(array $config): void
{
    // Verify state
    $state = get('state');
    $storedState = Session::get('oauth_state');

    if (!$state || $state !== $storedState) {
        Session::setFlash('error', 'Invalid OAuth state. Please try again.');
        redirect('/settings.php');
    }

    Session::remove('oauth_state');

    // Check for errors
    $error = get('error');
    if ($error) {
        $errorDescription = get('error_description', 'Unknown error');
        Session::setFlash('error', 'Authorization failed: ' . $errorDescription);
        redirect('/settings.php');
    }

    // Get authorization code
    $code = get('code');
    if (!$code) {
        Session::setFlash('error', 'No authorization code received.');
        redirect('/settings.php');
    }

    // Exchange code for tokens
    $tokenResponse = exchangeCodeForTokens($config, $code);

    if (!$tokenResponse || isset($tokenResponse['error'])) {
        $errorMsg = $tokenResponse['error_description'] ?? 'Failed to obtain access token';
        Session::setFlash('error', 'Token exchange failed: ' . $errorMsg);
        redirect('/settings.php');
    }

    // Store tokens
    $userId = Auth::id();
    $expiresAt = date('Y-m-d H:i:s', time() + ($tokenResponse['expires_in'] ?? 3600));

    // Check if token already exists
    $existing = Database::queryOne(
        'SELECT id FROM oauth_tokens WHERE user_id = ? AND provider = ?',
        [$userId, 'microsoft']
    );

    if ($existing) {
        Database::execute(
            'UPDATE oauth_tokens SET access_token = ?, refresh_token = ?, expires_at = ?, updated_at = NOW()
             WHERE user_id = ? AND provider = ?',
            [
                $tokenResponse['access_token'],
                $tokenResponse['refresh_token'] ?? null,
                $expiresAt,
                $userId,
                'microsoft'
            ]
        );
    } else {
        Database::execute(
            'INSERT INTO oauth_tokens (user_id, provider, access_token, refresh_token, expires_at)
             VALUES (?, ?, ?, ?, ?)',
            [
                $userId,
                'microsoft',
                $tokenResponse['access_token'],
                $tokenResponse['refresh_token'] ?? null,
                $expiresAt
            ]
        );
    }

    // Clear tile caches
    cacheClear("email_{$userId}");
    cacheClear("calendar_{$userId}");
    cacheClear("todo_{$userId}");

    Session::setFlash('success', 'Microsoft 365 connected successfully!');
    redirect('/settings.php');
}

/**
 * Exchange authorization code for tokens
 */
function exchangeCodeForTokens(array $config, string $code): ?array
{
    $tokenUrl = 'https://login.microsoftonline.com/' . $config['tenant_id'] . '/oauth2/v2.0/token';

    $params = [
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'code' => $code,
        'redirect_uri' => $config['redirect_uri'],
        'grant_type' => 'authorization_code',
        'scope' => implode(' ', $config['scopes'])
    ];

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query($params),
            'ignore_errors' => true
        ]
    ]);

    $response = file_get_contents($tokenUrl, false, $context);

    if (!$response) {
        return null;
    }

    return json_decode($response, true);
}

/**
 * Disconnect Microsoft account
 */
function disconnect(): void
{
    Auth::requireCsrf();

    $userId = Auth::id();

    Database::execute(
        'DELETE FROM oauth_tokens WHERE user_id = ? AND provider = ?',
        [$userId, 'microsoft']
    );

    // Clear caches
    cacheClear("email_{$userId}");
    cacheClear("calendar_{$userId}");
    cacheClear("todo_{$userId}");

    Session::setFlash('success', 'Microsoft 365 disconnected.');
    redirect('/settings.php');
}
