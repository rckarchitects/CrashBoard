<?php
/**
 * CrashBoard Setup Script
 *
 * Run this once to create the initial admin user.
 * Delete this file after setup for security!
 */

declare(strict_types=1);

// Check if already set up
$configPath = __DIR__ . '/config/config.php';
if (!file_exists($configPath)) {
    die("Please copy config/config.example.php to config/config.php and configure your settings first.\n");
}

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

// Check if users already exist
$existingUser = Database::queryOne('SELECT COUNT(*) as count FROM users');
if ($existingUser && $existingUser['count'] > 0) {
    die("Setup already completed. Users exist in the database.\nDelete this file for security.\n");
}

// Handle form submission
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($username) || empty($email) || empty($password)) {
        $error = 'All fields are required.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        $result = Auth::createUser($username, $email, $password);

        if ($result['success']) {
            $success = "User created successfully! You can now <a href='/login.php' class='text-primary-600 underline'>log in</a>.<br><br><strong>Important:</strong> Delete this setup.php file for security!";
        } else {
            $error = $result['error'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup - CrashBoard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-screen bg-gradient-to-br from-sky-900 to-sky-700 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl p-8 max-w-md w-full">
        <h1 class="text-2xl font-bold text-gray-900 mb-2">CrashBoard Setup</h1>
        <p class="text-gray-600 mb-6">Create your admin account to get started.</p>

        <?php if ($error): ?>
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">
            <?= $success ?>
        </div>
        <?php else: ?>
        <form method="POST" class="space-y-4">
            <div>
                <label for="username" class="block text-sm font-medium text-gray-700">Username</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    required
                    minlength="3"
                    maxlength="50"
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                    placeholder="admin"
                >
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-gray-700">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    required
                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                    placeholder="admin@example.com"
                >
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-gray-700">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                    minlength="8"
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                    placeholder="Minimum 8 characters"
                >
            </div>

            <div>
                <label for="confirm_password" class="block text-sm font-medium text-gray-700">Confirm Password</label>
                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    required
                    minlength="8"
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-primary-500 focus:border-primary-500"
                >
            </div>

            <button
                type="submit"
                class="w-full py-2.5 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500 transition-colors"
            >
                Create Account
            </button>
        </form>
        <?php endif; ?>

        <div class="mt-6 pt-6 border-t border-gray-200">
            <h2 class="text-sm font-medium text-gray-900 mb-2">Setup Checklist</h2>
            <ul class="text-sm text-gray-600 space-y-1">
                <li class="flex items-center">
                    <svg class="w-4 h-4 mr-2 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                    </svg>
                    Copy config.example.php to config.php
                </li>
                <li class="flex items-center">
                    <svg class="w-4 h-4 mr-2 <?= file_exists($configPath) ? 'text-green-500' : 'text-gray-300' ?>" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                    </svg>
                    Configure database settings
                </li>
                <li class="flex items-center">
                    <svg class="w-4 h-4 mr-2 text-gray-300" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                    </svg>
                    Import sql/schema.sql into database
                </li>
                <li class="flex items-center">
                    <svg class="w-4 h-4 mr-2 text-gray-300" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                    </svg>
                    Create admin user (this page)
                </li>
                <li class="flex items-center">
                    <svg class="w-4 h-4 mr-2 text-gray-300" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                    </svg>
                    Delete setup.php file
                </li>
            </ul>
        </div>
    </div>
</body>
</html>
