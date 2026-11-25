<?php
require __DIR__ . '/../app/bootstrap.php';
$current_user = require_admin($pdo);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') require_csrf();

$recentInventoryBackups = [];
try {
    $recentInventoryBackups = $pdo->query("
        SELECT tablename FROM pg_tables
        WHERE schemaname = 'public' AND tablename ~ '^bak_notes_[0-9]{8}_[0-9]{4}$'
        ORDER BY tablename DESC LIMIT 3
    ")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    error_log('Unable to list inventory snapshots: ' . $e->getMessage());
}

$message      = null;
$message_type = 'ok';

if (($_POST['action'] ?? '') === 'save_external_dashboard') {
    $selected = $_POST['external_sections'] ?? [];
    $selected = is_array($selected) ? $selected : [];
    $visibility = [];
    foreach ($externalDashboardSections as $key => $label) {
        $visibility[$key] = in_array($key, $selected, true);
    }
    try {
        $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('external_dashboard_sections', ?) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([json_encode($visibility, JSON_THROW_ON_ERROR)]);
        $externalDashboardVisibility = $visibility;
        audit_event('settings.external_dashboard.update', $visibility);
        $message = 'Dashboard visibility saved.';
    } catch (PDOException $e) {
        error_log('Dashboard visibility update failed: ' . $e->getMessage());
        $message = 'Unable to save dashboard visibility.';
        $message_type = 'error';
    }
}

// Inventory resets deliberately exclude import history and backup snapshots.
$inventoryResetTargets = [
    'external' => ['inventory_external'],
    'satellite' => ['inventory_satellite'],
    'factory' => ['inventory_external', 'inventory_satellite', 'server_notes'],
];
if (($_POST['action'] ?? '') === 'reset_inventory') {
    $target = $_POST['inventory_target'] ?? '';
    if (!is_string($target) || !isset($inventoryResetTargets[$target]) || ($_POST['confirmation'] ?? '') !== 'RESET') {
        header('Location: settings.php?inventory_reset=confirmation#inventory-data', true, 303);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $deleted = [];
        foreach ($inventoryResetTargets[$target] as $table) {
            $deleted[$table] = $pdo->exec('DELETE FROM ' . $table);
        }
        $pdo->commit();
        audit_event('inventory.reset', ['target' => $target, 'deleted' => $deleted]);
        $result = $target;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Inventory reset failed: ' . $e->getMessage());
        $result = 'failed';
    }
    header('Location: settings.php?' . http_build_query(['inventory_reset' => $result]) . '#inventory-data', true, 303);
    exit;
}

// --- AZIONE: Aggiorna o ripristina nome Customer ---
if (($_POST['action'] ?? '') === 'update_customer_name') {
    $new_customer_name = trim((string) ($_POST['customer_name'] ?? ''));
    if ($new_customer_name === '') {
        $message = 'Customer name is required.';
        $message_type = 'error';
    } elseif (mb_strlen($new_customer_name) > 100 || preg_match('/[\x00-\x1F\x7F]/u', $new_customer_name)) {
        $message = 'Customer name must not exceed 100 characters and cannot contain control characters.';
        $message_type = 'error';
    } else {
        $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('customer_name', ?, CURRENT_TIMESTAMP) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([$new_customer_name]);
        $customerName = $new_customer_name;
        audit_event('settings.customer_name.update', ['customer_name' => $new_customer_name]);
        $message = 'Customer name updated successfully.';
    }
}

if (($_POST['action'] ?? '') === 'reset_customer_name') {
    $stmt = $pdo->prepare("INSERT INTO app_settings (setting_key, setting_value, updated_at) VALUES ('customer_name', 'Acme Corporation', CURRENT_TIMESTAMP) ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = CURRENT_TIMESTAMP");
    $stmt->execute();
    $customerName = 'Acme Corporation';
    audit_event('settings.customer_name.reset');
    $message = 'Default customer name restored.';
}

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
                <form method="POST" class="customer-name-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="action" value="update_customer_name">
                    <label for="customer-name">Customer name</label>
                    <div class="customer-name-actions">
                        <input id="customer-name" type="text" name="customer_name" value="<?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>" maxlength="100" required>
                        <button type="submit" class="btn-add-user">Save name</button>
                    </div>
                </form>
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
                    <form method="POST" onsubmit="return confirm('Restore the default customer name Acme Corporation?')">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="action" value="reset_customer_name">
                        <button type="submit" class="btn-action btn-action-reset">↺ Restore default name</button>
                    </form>
                </div>
            </div>
        </div>
    </div>


    <section class="settings-section" id="external-dashboard">
        <div class="settings-section-header">
            <h2 class="settings-section-title">External Inventory on Dashboard</h2>
        </div>
        <div class="settings-section-body">
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_external_dashboard">
                <fieldset>
                    <legend>Show comparison sections</legend>
                    <?php foreach ($externalDashboardSections as $key => $label): ?>
                        <label style="display:block;margin:8px 0">
                            <input type="checkbox" name="external_sections[]" value="<?= htmlspecialchars($key) ?>"
                                   <?= $externalDashboardVisibility[$key] ? 'checked' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
                <button type="submit" class="btn-add-user" style="margin-top:12px">Save Dashboard Settings</button>
            </form>
        </div>
    </section>

    <section class="settings-section" id="inventory-data">
        <div class="settings-section-header">
            <h2 class="settings-section-title">Inventory Data</h2>
        </div>
        <div class="settings-section-body">
            <?php if (isset($_GET['import_success'])): ?>
                <p role="status">Satellite import completed. <?= (int) $_GET['import_success'] ?> imported, <?= (int) ($_GET['import_skipped'] ?? 0) ?> skipped.</p>
            <?php endif; ?>
            <?php if (isset($_GET['external_success'])): ?>
                <p role="status">External import completed. <?= (int) $_GET['external_success'] ?> imported, <?= (int) ($_GET['external_inserted'] ?? 0) ?> inserted, <?= (int) ($_GET['external_updated'] ?? 0) ?> updated, <?= (int) ($_GET['external_skipped'] ?? 0) ?> skipped.</p>
            <?php endif; ?>
            <?php if (($_GET['backup'] ?? '') === 'success'): ?>
                <p role="status">Inventory and notes snapshot created.</p>
            <?php endif; ?>
            <?php
            $resetLabels = ['external' => 'Reset External', 'satellite' => 'Reset Satellite', 'factory' => 'Factory Reset'];
            $resetResult = $_GET['inventory_reset'] ?? '';
            if (is_string($resetResult) && isset($resetLabels[$resetResult])):
            ?>
                <p role="status"><?= $resetLabels[$resetResult] ?> completed.</p>
            <?php elseif ($resetResult === 'confirmation'): ?>
                <p role="alert">Type RESET to confirm the operation.</p>
            <?php elseif ($resetResult === 'failed'): ?>
                <p role="alert">Inventory reset failed. Check the server log.</p>
            <?php endif; ?>
            <h3>Import Inventory</h3>
            <?php foreach (['Satellite' => 'satellite', 'External' => 'external'] as $label => $source): ?>
                <form action="import_<?= $source ?>.php" method="post" enctype="multipart/form-data" class="customer-logo-upload-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <label for="inventory-<?= $source ?>"><?= $label ?> CSV</label>
                    <input id="inventory-<?= $source ?>" type="file" name="<?= $source ?>_csv" accept=".csv,text/csv" required>
                    <button type="submit" class="btn-add-user">Import <?= $label ?> CSV</button>
                </form>
            <?php endforeach; ?>
            <h3>Inventory Backups</h3>
            <p>Create a snapshot of both inventories and server notes in PostgreSQL.</p>
            <form action="backup_db.php" method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <button type="submit" class="btn-add-user">Create Inventory Snapshot</button>
            </form>
            <h4>Recent Snapshots</h4>
            <?php if ($recentInventoryBackups): ?>
                <ul>
                    <?php foreach ($recentInventoryBackups as $snapshot): ?>
                        <?php $snapshotDate = DateTimeImmutable::createFromFormat('!Ymd_Hi', substr($snapshot, 10)); ?>
                        <li><?= htmlspecialchars($snapshotDate ? $snapshotDate->format('Y-m-d H:i') : $snapshot) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>No snapshots found.</p>
            <?php endif; ?>
            <h3>Reset Inventory Data</h3>
            <p>Factory Reset deletes both inventories and server notes. Import history, backups, users, settings, and CSV files are preserved. Imports can repopulate cleared inventories.</p>
            <?php foreach ($resetLabels as $target => $label): ?>
                <form action="settings.php" method="post" class="customer-logo-upload-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="action" value="reset_inventory">
                    <input type="hidden" name="inventory_target" value="<?= $target ?>">
                    <label for="reset-<?= $target ?>">Type RESET to confirm <?= $label ?></label>
                    <input id="reset-<?= $target ?>" name="confirmation" required pattern="RESET" autocomplete="off">
                    <button type="submit" class="btn-action btn-action-delete"><?= $label ?></button>
                </form>
            <?php endforeach; ?>
        </div>
    </section>
    <div class="settings-section" id="user-accounts">
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
