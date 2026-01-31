<?php
/**
 * Microsoft OAuth2 Callback
 *
 * Redirects to the main auth handler with callback action.
 */

declare(strict_types=1);

// Pass through to main auth handler
$_GET['action'] = 'callback';
require_once __DIR__ . '/auth.php';
