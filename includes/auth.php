<?php
require_once 'config.php';

class Auth
{
    private $usersFile;

    public function __construct()
    {
        $this->usersFile = USERS_FILE;
        $this->initializeUsers();
    }

    private function initializeUsers()
    {
        if (!file_exists($this->usersFile)) {
            $initialData = [
                'users' => [
                    'admin' => [
                        'password' => password_hash('admin123', PASSWORD_BCRYPT),
                        'email' => 'admin@securestream.com',
                        'role' => 'admin',
                        'created_at' => date('Y-m-d H:i:s')
                    ],
                    'user' => [
                        'password' => password_hash('user123', PASSWORD_BCRYPT),
                        'email' => 'user@securestream.com',
                        'role' => 'user',
                        'created_at' => date('Y-m-d H:i:s')
                    ]
                ],
                'settings' => [
                    'max_users' => 100,
                    'registration_open' => true
                ]
            ];

            file_put_contents($this->usersFile, json_encode($initialData, JSON_PRETTY_PRINT));
        }
    }

    public function register($username, $password, $email)
    {
        $data = json_decode(file_get_contents($this->usersFile), true);

        // Validate username
        if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
            return ['success' => false, 'error' => 'Username must be 3-20 characters (letters, numbers, underscore only)'];
        }

        // Check if username exists
        if (isset($data['users'][$username])) {
            return ['success' => false, 'error' => 'Username already exists'];
        }

        // Validate email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email address'];
        }

        // Hash password
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        // Add user
        $data['users'][$username] = [
            'password' => $hashedPassword,
            'email' => $email,
            'role' => 'user',
            'created_at' => date('Y-m-d H:i:s'),
            'last_login' => null
        ];

        // Save to file
        if (file_put_contents($this->usersFile, json_encode($data, JSON_PRETTY_PRINT))) {
            return ['success' => true, 'message' => 'Registration successful'];
        }

        return ['success' => false, 'error' => 'Failed to save user data'];
    }

    public function login($username, $password)
    {
        $data = json_decode(file_get_contents($this->usersFile), true);

        // Check if user exists
        if (!isset($data['users'][$username])) {
            return ['success' => false, 'error' => 'Invalid username or password'];
        }

        $user = $data['users'][$username];

        // Verify password
        if (password_verify($password, $user['password'])) {
            // Set session
            $_SESSION['user_id'] = $username;
            $_SESSION['authenticated'] = true;
            $_SESSION['role'] = $user['role'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

            // Update last login
            $data['users'][$username]['last_login'] = date('Y-m-d H:i:s');
            file_put_contents($this->usersFile, json_encode($data, JSON_PRETTY_PRINT));

            return [
                'success' => true,
                'user' => [
                    'username' => $username,
                    'role' => $user['role'],
                    'email' => $user['email']
                ]
            ];
        }

        return ['success' => false, 'error' => 'Invalid username or password'];
    }

    public function logout()
    {
        session_destroy();
        return ['success' => true];
    }

    public function isAdmin()
    {
        return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
    }

    public function getCurrentUser()
    {
        return isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    }

    public function getUserCount()
    {
        $data = json_decode(file_get_contents($this->usersFile), true);
        return count($data['users']);
    }
}
?>