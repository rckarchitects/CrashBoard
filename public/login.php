<?php
/**
 * Login Page
 *
 * Handles user authentication.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/auth.php';

// Start session immediately so the CSRF cookie is set on first load (avoids "Invalid security token" on submit)
Session::init();

// Prevent caching so the form always gets a fresh CSRF token
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Allow redirect param so client can send expired-session users back to the page they were on
if (isset($_GET['redirect']) && is_string($_GET['redirect'])) {
    $r = trim($_GET['redirect']);
    if ($r !== '' && $r[0] === '/') {
        Session::setFlash('redirect_to', $r);
    }
}

// Redirect if already logged in
if (Auth::check()) {
    redirect('/index.php');
}

$error = '';
$username = '';

// Handle login form submission
if (isPost()) {
    if (!Auth::verifyCsrf()) {
        Session::setFlash('error', 'Your session expired or the security token was invalid. Please try again.');
        redirect('/login.php');
    }

    $username = trim(post('username', ''));
    $password = post('password', '');

    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        $result = Auth::attempt($username, $password);

        if ($result['success']) {
            $isPrivateComputer = !empty(post('private_computer'));

            if ($isPrivateComputer) {
                $userId = (int) $_SESSION['user_id'];
                $userData = $_SESSION['user_data'] ?? [];
                $redirectTo = Session::flash('redirect_to', '/index.php');
                Session::destroy();
                $rememberLifetime = (int) config('session.lifetime_remember', 2592000);
                Session::startWithLifetime($rememberLifetime);
                Session::setUser($userId, $userData);
                Session::set('private_computer', true);
                redirect($redirectTo);
            }

            // Normal session: record expiry so header can show time left
            Session::set('session_expires_at', time() + (int) config('session.lifetime', 604800));

            // Redirect to original destination or dashboard
            $redirectTo = Session::flash('redirect_to', '/index.php');
            redirect($redirectTo);
        } else {
            $error = $result['error'];
        }
    }
}

// Get flash messages
$flashError = Session::flash('error');
if ($flashError) {
    $error = $flashError;
}

$pageTitle = 'Login - CrashBoard';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            200: '#bae6fd',
                            300: '#7dd3fc',
                            400: '#38bdf8',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            800: '#075985',
                            900: '#0c4a6e',
                        }
                    }
                }
            }
        }
    </script>
    <style>
        .login-bg {
            background: linear-gradient(135deg, #0c4a6e 0%, #075985 50%, #0369a1 100%);
        }
    </style>
</head>
<body class="h-full login-bg">
    <div class="min-h-full flex flex-col justify-center py-12 sm:px-6 lg:px-8">
        <div class="sm:mx-auto sm:w-full sm:max-w-md">
            <h1 class="text-center text-4xl font-bold text-white tracking-tight">
                CrashBoard
            </h1>
            <p class="mt-2 text-center text-sm text-sky-200">
                Your personal command center
            </p>
        </div>

        <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md">
            <div class="bg-white py-8 px-4 shadow-2xl sm:rounded-xl sm:px-10">
                <form class="space-y-6" action="" method="POST">
                    <?= Session::csrfField() ?>

                    <?php if ($error): ?>
                    <div class="rounded-lg bg-red-50 p-4 border border-red-200">
                        <div class="flex">
                            <div class="flex-shrink-0">
                                <svg class="h-5 w-5 text-red-400" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                                </svg>
                            </div>
                            <div class="ml-3">
                                <p class="text-sm text-red-700"><?= e($error) ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div>
                        <label for="username" class="block text-sm font-medium text-gray-700">
                            Username or Email
                        </label>
                        <div class="mt-1">
                            <input
                                id="username"
                                name="username"
                                type="text"
                                autocomplete="username"
                                required
                                value="<?= e($username) ?>"
                                class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                                placeholder="Enter your username"
                            >
                        </div>
                    </div>

                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700">
                            Password
                        </label>
                        <div class="mt-1">
                            <input
                                id="password"
                                name="password"
                                type="password"
                                autocomplete="current-password"
                                required
                                class="appearance-none block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm placeholder-gray-400 focus:outline-none focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
                                placeholder="Enter your password"
                            >
                        </div>
                    </div>

                    <div class="flex items-center">
                        <input
                            id="private_computer"
                            name="private_computer"
                            type="checkbox"
                            value="1"
                            class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                        >
                        <label for="private_computer" class="ml-2 block text-sm text-gray-700">
                            This is a private computer — keep me signed in longer
                        </label>
                    </div>

                    <div>
                        <button
                            type="submit"
                            class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors"
                        >
                            Sign in
                        </button>
                    </div>
                </form>
            </div>

            <p class="mt-6 text-center text-xs text-sky-200">
                Secure login with rate limiting and session protection
            </p>
        </div>
    </div>

    <script>
        // Focus on username field on load
        document.getElementById('username').focus();
    </script>
</body>
</html>
