<?php
require __DIR__ . '/../app/bootstrap.php';

if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    require_csrf();
    $attempts = (int) ($_SESSION['login_attempts'] ?? 0);
    $blockedUntil = (int) ($_SESSION['login_blocked_until'] ?? 0);
    if ($blockedUntil > time()) {
        $error = 'Too many attempts. Please wait before trying again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $stmt = $pdo->prepare('SELECT id, username, password, role FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['login_attempts'] = 0;
            unset($_SESSION['login_blocked_until']);
            csrf_token();
            audit_event('auth.login');
            header('Location: index.php');
            exit;
        }

        $attempts++;
        $_SESSION['login_attempts'] = $attempts;
        if ($attempts >= 5) {
            $_SESSION['login_blocked_until'] = time() + min(900, 30 * ($attempts - 4));
        }
        usleep(min(2000000, 250000 * $attempts));
        error_log('Failed login attempt for username: ' . preg_replace('/[^a-zA-Z0-9_.@-]/', '?', $username));
        $error = 'Invalid credentials. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — MoonCrater</title>
    <link rel="icon" type="image/png" sizes="any" href="assets/img/favicon-mooncrater-130.png">
    <link rel="shortcut icon" type="image/png" href="assets/img/favicon-mooncrater-130.png">
    <link rel="apple-touch-icon" href="assets/img/favicon-mooncrater-130.png">
    <link rel="stylesheet" href="assets/css/base.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <link rel="stylesheet" href="assets/css/style-login.css">
    <script>
        // Forza sempre il tema dark
        document.documentElement.setAttribute('data-theme', 'dark');
    </script>
</head>
<body>

<div class="login-wrapper">
<div class="login-box">

    <!-- =============================================
         COLONNA SINISTRA — Branding
         ============================================= -->
    <div class="login-brand">

        <!-- MoonCrater product logo -->
        <img src="assets/img/mooncrater-icon-v2.png"
             alt="MoonCrater"
             class="brand-product-logo">

        <!-- Nome prodotto -->
        <div class="brand-product-name"><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></div>
        <div class="brand-product-version">Version <?= htmlspecialchars($appVersion) ?></div>
        <div class="brand-subtitle"><?= htmlspecialchars($appSubtitle, ENT_QUOTES, 'UTF-8') ?></div>

        <div class="brand-divider"></div>

        <p class="brand-description">
            Unified patching visibility across Ivanti &amp; Red Hat Satellite inventories.
            Monitor agent health, track kernel versions, and manage host exclusions
            across your entire Linux infrastructure.
        </p>

        <!-- Logo cliente -->
        <div class="brand-client">
            <img src="<?= htmlspecialchars($customerLogo, ENT_QUOTES, 'UTF-8') ?>"
                 alt="<?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>"
                 class="brand-client-logo">
            <div>
                <div class="brand-client-label">Customer Portal</div>
                <div class="brand-client-name"><?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </div>

    </div>

    <!-- =============================================
         COLONNA DESTRA — Form
         ============================================= -->
    <div class="login-form-col">

            <div class="login-title">Sign in</div>
            <div class="login-subtitle">Enter your credentials to access the portal</div>

            <?php if ($error): ?>
            <div class="login-error">
                <span>⚠</span>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <form method="POST" autocomplete="on">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

                <div class="login-field">
                    <label for="username">Username</label>
                    <input type="text"
                           id="username"
                           name="username"
                           autocomplete="username"
                           autocapitalize="none"
                           spellcheck="false"
                           placeholder="Enter your username"
                           required
                           autofocus
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                </div>

                <div class="login-field">
                    <label for="password">Password</label>
                    <input type="password"
                           id="password"
                           name="password"
                           autocomplete="current-password"
                           placeholder="Enter your password"
                           required>
                </div>

                <button type="submit" class="login-btn">
                    SIGN IN TO PORTAL
                </button>

            </form>

            <div class="login-footer">
                © <?= date('Y') ?> MoonCrater &nbsp;·&nbsp; Infrastructure Control Plane Patching<br>
                <span style="opacity: 0.6;">Unauthorized access is prohibited</span><br>
                <a href="https://github.com/markhawks/MoonCrater" rel="noopener noreferrer" style="color:inherit;opacity:.75;">Source code · AGPL-3.0-or-later</a>
            </div>

    </div><!-- /login-form-col -->

</div><!-- /login-box -->
</div><!-- /login-wrapper -->

</body>
</html>
