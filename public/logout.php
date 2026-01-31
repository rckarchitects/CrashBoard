<?php
/**
 * Logout Handler
 *
 * Destroys the session and redirects to login.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

Auth::logout();

Session::setFlash('success', 'You have been logged out successfully.');

redirect('/login.php');
