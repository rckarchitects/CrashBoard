<?php
/**
 * CrashBoard Setup Script
 *
 * Run this once to create the initial admin user.
 * Delete this file after setup for security!
 */

// Enable error display for setup
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Check if config exists
$configPath = __DIR__ . '/config/config.php';
$configExists = file_exists($configPath);

$error = '';
$success = '';
$dbConnected = false;
$tablesExist = false;
$usersExist = false;

// Only try database operations if config exists
if ($configExists) {
    try {
        require_once __DIR__ . '/config/database.php';
        $dbConnected = true;

        // Check if tables exist
        try {
            $result = Database::queryOne("SHOW TABLES LIKE 'users'");
            $tablesExist = ($result !== null);

            if ($tablesExist) {
                $countResult = Database::queryOne('SELECT COUNT(*) as count FROM users');
                $usersExist = ($countResult && $countResult['count'] > 0);
            }
        } catch (Exception $e) {
            $tablesExist = false;
        }
    } catch (Exception $e) {
        $error = 'Database connection failed: ' . $e->getMessage();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbConnected && $tablesExist && !$usersExist) {
    require_once __DIR__ . '/includes/functions.php';
    require_once __DIR__ . '/includes/auth.php';

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
        try {
            $result = Auth::createUser($username, $email, $password);

            if ($result['success']) {
                $success = "User created successfully!";
                $usersExist = true;
            } else {
                $error = $result['error'];
            }
        } catch (Exception $e) {
            $error = 'Error creating user: ' . $e->getMessage();
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
</head>
<body class="min-h-screen bg-gradient-to-br from-sky-900 to-sky-700 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl shadow-2xl p-8 max-w-md w-full">
        <h1 class="text-2xl font-bold text-gray-900 mb-2">CrashBoard Setup</h1>
        <p class="text-gray-600 mb-6">Complete the steps below to get started.</p>

        <?php if ($error): ?>
        <div class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="mb-4 p-4 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">
            <?= htmlspecialchars($success) ?>
            <br><br>
            <a href="/login.php" class="font-medium underline">Click here to log in</a>
            <br><br>
            <strong>Important:</strong> Delete this setup.php file for security!
        </div>
        <?php endif; ?>

        <!-- Setup Checklist -->
        <div class="space-y-4 mb-6">
            <h2 class="text-sm font-medium text-gray-900">Setup Checklist</h2>

            <!-- Step 1: Config file -->
            <div class="flex items-start p-3 rounded-lg <?= $configExists ? 'bg-green-50' : 'bg-yellow-50' ?>">
                <div class="flex-shrink-0">
                    <?php if ($configExists): ?>
                    <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                    </svg>
                    <?php else: ?>
                    <svg class="w-5 h-5 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    <?php endif; ?>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium <?= $configExists ? 'text-green-800' : 'text-yellow-800' ?>">
                        1. Create config file
                    </p>
                    <?php if (!$configExists): ?>
                    <p class="text-xs text-yellow-700 mt-1">
                        Copy <code>config/config.example.php</code> to <code>config/config.php</code> and edit with your settings.
                    </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Step 2: Database connection -->
            <div class="flex items-start p-3 rounded-lg <?= $dbConnected ? 'bg-green-50' : ($configExists ? 'bg-red-50' : 'bg-gray-50') ?>">
                <div class="flex-shrink-0">
                    <?php if ($dbConnected): ?>
                    <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                    </svg>
                    <?php elseif ($configExists): ?>
                    <svg class="w-5 h-5 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                    </svg>
                    <?php else: ?>
                    <svg class="w-5 h-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
                    </svg>
                    <?php endif; ?>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium <?= $dbConnected ? 'text-green-800' : ($configExists ? 'text-red-800' : 'text-gray-500') ?>">
                        2. Database connection
                    </p>
                    <?php if ($configExists && !$dbConnected): ?>
                    <p class="text-xs text-red-700 mt-1">
                        Check your database settings in config.php
                    </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Step 3: Tables created -->
            <div class="flex items-start p-3 rounded-lg <?= $tablesExist ? 'bg-green-50' : ($dbConnected ? 'bg-yellow-50' : 'bg-gray-50') ?>">
                <div class="flex-shrink-0">
                    <?php if ($tablesExist): ?>
                    <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                    </svg>
                    <?php elseif ($dbConnected): ?>
                    <svg class="w-5 h-5 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
                    </svg>
                    <?php else: ?>
                    <svg class="w-5 h-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
                    </svg>
                    <?php endif; ?>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium <?= $tablesExist ? 'text-green-800' : ($dbConnected ? 'text-yellow-800' : 'text-gray-500') ?>">
                        3. Import database schema
                    </p>
                    <?php if ($dbConnected && !$tablesExist): ?>
                    <p class="text-xs text-yellow-700 mt-1">
                        Import <code>sql/schema.sql</code> into your database using phpMyAdmin or command line.
                    </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Step 4: Create user -->
            <div class="flex items-start p-3 rounded-lg <?= $usersExist ? 'bg-green-50' : ($tablesExist ? 'bg-blue-50' : 'bg-gray-50') ?>">
                <div class="flex-shrink-0">
                    <?php if ($usersExist): ?>
                    <svg class="w-5 h-5 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                    </svg>
                    <?php elseif ($tablesExist): ?>
                    <svg class="w-5 h-5 text-blue-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-8.707l-3-3a1 1 0 00-1.414 1.414L10.586 9H7a1 1 0 100 2h3.586l-1.293 1.293a1 1 0 101.414 1.414l3-3a1 1 0 000-1.414z" clip-rule="evenodd"/>
                    </svg>
                    <?php else: ?>
                    <svg class="w-5 h-5 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>
                    </svg>
                    <?php endif; ?>
                </div>
                <div class="ml-3">
                    <p class="text-sm font-medium <?= $usersExist ? 'text-green-800' : ($tablesExist ? 'text-blue-800' : 'text-gray-500') ?>">
                        4. Create admin user
                    </p>
                </div>
            </div>
        </div>

        <!-- User Creation Form -->
        <?php if ($tablesExist && !$usersExist): ?>
        <form method="POST" class="space-y-4 border-t pt-6">
            <h3 class="font-medium text-gray-900">Create Admin Account</h3>

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
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500"
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
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500"
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
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500"
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
                    class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:outline-none focus:ring-2 focus:ring-sky-500 focus:border-sky-500"
                >
            </div>

            <button
                type="submit"
                class="w-full py-2.5 px-4 border border-transparent rounded-lg shadow-sm text-sm font-medium text-white bg-sky-600 hover:bg-sky-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-sky-500 transition-colors"
            >
                Create Account
            </button>
        </form>
        <?php elseif ($usersExist): ?>
        <div class="border-t pt-6 text-center">
            <p class="text-green-700 font-medium mb-4">Setup complete!</p>
            <a href="/login.php" class="inline-block px-6 py-2 bg-sky-600 text-white rounded-lg hover:bg-sky-700 transition-colors">
                Go to Login
            </a>
            <p class="text-xs text-gray-500 mt-4">
                Remember to delete this setup.php file for security.
            </p>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
