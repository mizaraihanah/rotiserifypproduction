<?php
session_start();
require_once 'config/db_connection.php';

$error_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
        if (!$email) {
            throw new Exception('Invalid email format.');
        }

        $password = $_POST['password'];
        if (empty($password) || strlen($password) < 8) {
            throw new Exception('Password must be at least 8 characters long.');
        }

        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception('Invalid CSRF token.');
        }

        $stmt = $conn->prepare("SELECT * FROM tbl_users WHERE user_email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['user_password'])) {
            $_SESSION['user_id'] = htmlspecialchars($user['user_id']);
            $_SESSION['user_fullName'] = htmlspecialchars($user['user_fullName']);
            $_SESSION['user_email'] = htmlspecialchars($user['user_email']);
            $_SESSION['user_role'] = htmlspecialchars($user['user_role']);

            echo "<script>
                alert('Login successful!');
                window.location.href='dashboard.php';
            </script>";
            exit();
        } else {
            throw new Exception('Invalid email or password.');
        }
    } catch (Exception $e) {
        $error_message = $e->getMessage();
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Roti Seri Bakery Production Management System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
            padding: 40px;
            width: 100%;
            max-width: 400px;
            text-align: center;
        }

        .login-header {
            margin-bottom: 30px;
        }

        .logo-container {
            margin-bottom: 20px;
        }

        .logo-container img {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #4285f4;
            box-shadow: 0 4px 12px rgba(66, 133, 244, 0.2);
        }

        .login-header h1 {
            color: #4285f4;
            font-size: 24px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .login-header h2 {
            color: #4285f4;
            font-size: 18px;
            font-weight: 500;
            margin-bottom: 15px;
        }

        .login-header p {
            color: #4285f4;
            font-size: 16px;
            font-weight: 500;
        }

        .error-message {
            background-color: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            border-left: 4px solid #c33;
            text-align: left;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }

        .required {
            color: #e74c3c;
        }

        .password-field {
            position: relative;
        }

        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            background-color: #f8f9fa;
            transition: all 0.3s ease;
        }

        .password-field input {
            padding-right: 45px;
        }

        .form-group input:focus {
            outline: none;
            border-color: #4285f4;
            background-color: white;
            box-shadow: 0 0 0 3px rgba(66, 133, 244, 0.1);
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: #666;
            font-size: 16px;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 24px;
        }

        .toggle-password:hover {
            color: #4285f4;
        }

        .login-submit-btn {
            width: 100%;
            padding: 12px;
            background: #4285f4;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.3s ease;
            margin-bottom: 20px;
        }

        .login-submit-btn:hover {
            background: #3367d6;
        }

        .login-submit-btn:active {
            transform: translateY(1px);
        }

        .form-links {
            text-align: center;
        }

        .forgot-password {
            color: #4285f4;
            text-decoration: none;
            font-size: 14px;
        }

        .forgot-password:hover {
            text-decoration: underline;
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 30px 20px;
            }
            
            .login-header h1 {
                font-size: 20px;
            }
            
            .login-header h2 {
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo-container">
                <img src="assets/images/logo_name_w.png" alt="Roti Seri Bakery Logo">
            </div>
            <h1>Roti Seri Bakery Production</h1>
            <h2>Management System</h2>
            <p>Log in</p>
        </div>

        <?php if ($error_message): ?>
            <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        
        <form method="POST" class="login-form">
            <div class="form-group">
                <label for="email">Email<span class="required">*</span></label>
                <input type="email" id="email" name="email" required>
            </div>

            <div class="form-group">
                <label for="password">Password<span class="required">*</span></label>
                <div class="password-field">
                    <input type="password" id="password" name="password" required>
                    <button type="button" class="toggle-password" data-target="password">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
            </div>
            
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <button type="submit" class="login-submit-btn">Sign in</button>
            
            <div class="form-links">
                <a href="forgot_password.php" class="forgot-password">Forgot Password?</a>
            </div>
        </form>
    </div>

    <script src="js/login.js"></script>
    <script>
        // Add some interactive feedback (keeping this for the visual enhancement)
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('input').forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.style.transform = 'scale(1.02)';
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.style.transform = 'scale(1)';
                });
            });
        });
    </script>
</body>
</html>