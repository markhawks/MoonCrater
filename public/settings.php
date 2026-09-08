<?php
require __DIR__ . '/../app/bootstrap.php';
$current_user = require_admin($pdo);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') require_csrf();

$message      = null;
$message_type = 'ok';

// --- AZIONE: Aggiorna o ripristina logo Customer ---
if (($_POST['action'] ?? '') === 'upload_customer_logo') {
    $upload = $_FILES['customer_logo'] ?? null;
    if (!$upload || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $message = 'Select a valid image to upload.';
        $message_type = 'error';
    } elseif (($upload['size'] ?? 0) > 2 * 1024 * 1024) {
        $message = 'Customer logo must not exceed 2 MB.';
        $message_type = 'error';
    } else {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
        $allowed_mimes = ['image/png' => 'png', 'image/jpeg' => 'jpeg', 'image/webp' => 'webp'];
        $image_info = @getimagesize($upload['tmp_name']);
        if (!$image_info || !isset($allowed_mimes[$mime])) {
            $message = 'Only valid PNG, JPEG or WebP images are allowed.';
            $message_type = 'error';
        } else {
            $logo_data = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($upload['tmp_name']));
            $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('customer_logo_data', ?, CURRENT_TIMESTAMP) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP");
            $stmt->execute([$logo_data]);
            $customerLogo = $logo_data;
            audit_event('settings.customer_logo.upload', ['mime' => $mime, 'size' => (int)$upload['size']]);
            $message = 'Customer logo updated successfully.';
        }
    }
}

if (($_POST['action'] ?? '') === 'reset_customer_logo') {
    $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('customer_logo_data', 'assets/img/customer-default.svg', CURRENT_TIMESTAMP) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP");
    $stmt->execute();
    $customerLogo = 'assets/img/customer-default.svg';
    audit_event('settings.customer_logo.reset');
    $message = 'Default customer logo restored.';
}

// --- AZIONE: Aggiungi utente ---
if (($_POST['action'] ?? '') === 'add_user') {
    $new_username = trim($_POST['new_username'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');
    $new_role     = in_array($_POST['new_role'] ?? '', ['admin', 'user']) ? $_POST['new_role'] : 'user';

    if (empty($new_username) || empty($new_password)) {
        $message      = "Username and password are required.";
        $message_type = 'error';
    } elseif (strlen($new_password) < 8) {
        $message      = "Password must be at least 8 characters.";
        $message_type = 'error';
    } else {
        try {
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
            $stmt->execute([$new_username, $hash, $new_role]);
            audit_event('user.create', ['created_username' => $new_username, 'role' => $new_role]);
            $message = "User <strong>" . htmlspecialchars($new_username, ENT_QUOTES, "UTF-8") . "</strong> created successfully.";
        } catch (Exception $e) {
            $message = "Unable to create user."; error_log("Create user failed: " . $e->getMessage());
            $message_type = 'error';
        }
    }
}

// --- AZIONE: Rimuovi utente ---
if (($_POST['action'] ?? '') === 'delete_user') {
    $del_id = (int)($_POST['user_id'] ?? 0);

    if ($del_id === (int)$_SESSION['user_id']) {
        $message      = "You cannot delete your own account.";
        $message_type = 'error';
    } elseif ($del_id > 0) {
        try {
            $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
            $stmt->execute([$del_id]);
            $target_role = $stmt->fetchColumn();
            if ($target_role === 'admin') {
                $admin_count = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
                if ($admin_count <= 1) {
                    $message = 'The last administrator cannot be removed.';
                    $message_type = 'error';
                }
            }
            if ($message_type !== 'error') {
                $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
                $stmt->execute([$del_id]);
                if ($stmt->rowCount()) audit_event('user.delete', ['deleted_user_id' => $del_id]);
                $message = $stmt->rowCount() ? 'User removed successfully.' : 'User not found.';
            }
        } catch (Throwable $e) {
            $message = 'Unable to remove user.';
            error_log('Delete user failed: ' . $e->getMessage());
            $message_type = 'error';
        }
    }
}

// --- AZIONE: Reset password ---
if (($_POST['action'] ?? '') === 'reset_password') {
    $rst_id       = (int)($_POST['user_id'] ?? 0);
    $new_pwd      = trim($_POST['new_pwd'] ?? '');

    if (strlen($new_pwd) < 8) {
        $message      = "New password must be at least 8 characters.";
        $message_type = 'error';
    } elseif ($rst_id > 0) {
        try {
            $hash = password_hash($new_pwd, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ?, last_password_reset_at = CURRENT_TIMESTAMP WHERE id = ?");
            $stmt->execute([$hash, $rst_id]);
            audit_event('user.password_reset', ['target_user_id' => $rst_id]);
            $message = "Password reset successfully.";
        } catch (Exception $e) {
            $message = "Unable to reset password."; error_log("Reset password failed: " . $e->getMessage());
            $message_type = 'error';
        }
    }
}

// --- CARICA UTENTI ---
$users = $pdo->query("SELECT id, username, role, created_at, last_password_reset_at FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings — MoonCrater</title>
    <link rel="icon" type="image/png" sizes="any" href="assets/img/favicon-mooncrater-130.png">
    <link rel="stylesheet" href="assets/css/base.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <link rel="stylesheet" href="assets/css/style-settings.css">
    <script>document.documentElement.setAttribute('data-theme','dark');</script>
</head>
<body>

<div class="settings-container">

    <!-- HEADER PAGINA -->
    <div class="settings-header">
        <div class="settings-header-left">
            <img src="assets/img/mooncrater-icon-v2.png" alt="MoonCrater" style="height:36px; width:36px; object-fit:contain;">
            <div>
                <div class="settings-title">⚙️ Settings</div>
                <div class="settings-subtitle">MoonCrater — Administration</div>
            </div>
        </div>
        <div style="display:flex; align-items:center; gap:8px;">
            <a href="index.php" class="btn-header">← Back to Portal</a>
            <span class="btn-header no-hover" style="cursor:default;">
                👤 <strong><?= htmlspecialchars($_SESSION['username']) ?></strong>
            </span>
            <form action="logout.php" method="POST" style="margin:0"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><button type="submit" class="btn-header">Logout</button></form>
        </div>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?>">
        <span><?= $message_type === 'ok' ? '✓' : '⚠' ?></span>
        <span><?= $message ?></span>
    </div>
    <?php endif; ?>

    <div class="settings-section">
        <div class="settings-section-header">
            <div class="settings-section-title">🏢 Customer Identity</div>
            <span style="font-size:11px;color:var(--text-muted);">Login and portal header</span>
        </div>
        <div class="settings-section-body customer-logo-settings">
            <div class="customer-logo-preview">
                <img src="<?= htmlspecialchars($customerLogo, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="customer-logo-controls">
                <div style="font-size:13px;color:var(--text-primary);font-weight:700;"><?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="customer-logo-help">Upload a PNG, JPEG or WebP image. Maximum size: 2 MB.</div>
                <div class="customer-logo-actions">
                    <form method="POST" enctype="multipart/form-data" class="customer-logo-upload-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="upload_customer_logo">
                        <input type="file" name="customer_logo" accept="image/png,image/jpeg,image/webp" required>
                        <button type="submit" class="btn-add-user">⬆ Upload logo</button>
                    </form>
                    <form method="POST" onsubmit="return confirm('Restore the default Acme Corporation icon?')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="reset_customer_logo">
                        <button type="submit" class="btn-action btn-action-reset">↺ Restore default</button>
                    </form>
                </div>
            </div>
        </div>
    </div>


    <!-- ============================================================
         SEZIONE 1: LISTA UTENTI
         ============================================================ -->
    <div class="settings-section">
        <div class="settings-section-header">
            <div class="settings-section-title">👥 User Accounts</div>
            <span style="font-size:11px; color:var(--text-muted);"><?= count($users) ?> users registered</span>
        </div>
        <div class="settings-section-body" style="padding:0;">
            <table class="users-table">
                <thead>
                    <tr>
                        <th style="width:40px;">#</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Created at</th>
                        <th>Last password reset</th>
                        <th style="text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                <tr class="<?= $u['id'] == $_SESSION['user_id'] ? 'current-user' : '' ?>">
                    <td style="color:var(--text-faint);"><?= $u['id'] ?></td>
                    <td>
                        <strong><?= htmlspecialchars($u['username']) ?></strong>
                        <?php if ($u['id'] == $_SESSION['user_id']): ?>
                            <span style="font-size:10px; color:var(--color-infra); margin-left:6px;">(you)</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="role-badge role-<?= $u['role'] ?>">
                            <?= strtoupper($u['role']) ?>
                        </span>
                    </td>
                    <td class="user-date"><?= date('Y-m-d H:i', strtotime($u['created_at'])) ?></td>
                    <td class="user-date"><?= $u['last_password_reset_at'] ? date('Y-m-d H:i', strtotime($u['last_password_reset_at'])) : 'Never' ?></td>
                    <td>
                        <div class="row-actions" style="justify-content:flex-end;">
                            <!-- Reset password -->
                            <button class="btn-action btn-action-reset"
                                    onclick="openResetModal(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>')">
                                🔑 Reset pwd
                            </button>
                            <!-- Rimuovi (non su se stesso) -->
                            <?php if ($u['id'] != $_SESSION['user_id']): ?>
                            <form method="POST" style="margin:0;"
                                  onsubmit="return confirm('Remove user <?= htmlspecialchars($u['username']) ?>? This cannot be undone.')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action"  value="delete_user">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn-action btn-action-delete">🗑 Remove</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>


    <!-- ============================================================
         SEZIONE 2: AGGIUNGI UTENTE
         ============================================================ -->
    <div class="settings-section">
        <div class="settings-section-header">
            <div class="settings-section-title">➕ Add New User</div>
        </div>
        <div class="settings-section-body">
            <form method="POST" class="add-user-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="add_user">

                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="new_username" placeholder="e.g. jsmith" required
                           autocomplete="off">
                </div>

                <div class="form-group">
                    <label>Password <span style="font-weight:400; opacity:0.6;">(min 8 chars)</span></label>
                    <input type="password" name="new_password" placeholder="••••••••" required
                           autocomplete="new-password">
                </div>

                <div class="form-group">
                    <label>Role</label>
                    <select name="new_role">
                        <option value="user">User</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>

                <button type="submit" class="btn-add-user">
                    ➕ Add User
                </button>
            </form>
        </div>
    </div>


</div><!-- /settings-container -->


<!-- ============================================================
     MODAL RESET PASSWORD
     ============================================================ -->
<div class="modal-backdrop" id="reset-modal">
    <div class="modal-box">
        <div class="modal-title">🔑 Reset Password</div>
        <div class="modal-subtitle" id="reset-modal-subtitle">Set a new password for the user.</div>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action"  value="reset_password">
            <input type="hidden" name="user_id" id="reset-user-id">

            <div class="form-group">
                <label>New Password <span style="font-weight:400; opacity:0.6;">(min 8 chars)</span></label>
                <input type="password" name="new_pwd" id="reset-pwd-input"
                       placeholder="••••••••" required autocomplete="new-password"
                       style="padding:9px 12px; border-radius:6px; border:1px solid var(--border-default); background:rgba(255,255,255,0.07); color:var(--text-primary); font-size:13px; outline:none; width:100%; box-sizing:border-box;">
            </div>

            <div class="modal-actions">
                <button type="button" onclick="closeResetModal()"
                        class="btn-reset" style="padding:8px 16px;">
                    Cancel
                </button>
                <button type="submit" class="btn-add-user" style="padding:8px 18px;">
                    ✓ Confirm Reset
                </button>
            </div>
        </form>
    </div>
</div>


<script>
function openResetModal(userId, username) {
    document.getElementById('reset-user-id').value        = userId;
    document.getElementById('reset-modal-subtitle').textContent = 'Set a new password for: ' + username;
    document.getElementById('reset-pwd-input').value      = '';
    document.getElementById('reset-modal').classList.add('active');
    document.getElementById('reset-pwd-input').focus();
}

function closeResetModal() {
    document.getElementById('reset-modal').classList.remove('active');
}

// Chiudi cliccando fuori dal modal
document.getElementById('reset-modal').addEventListener('click', function(e) {
    if (e.target === this) closeResetModal();
});
</script>

</body>
</html>
