<?php
/**
 * VoucherReset - Admin Panel
 * Simple session-based authentication with router CRUD.
 */
require_once __DIR__ . '/includes/db.php';

session_name(SESSION_NAME);
session_start();

// ---- Authentication ----
$isLoggedIn = !empty($_SESSION['admin_logged_in']);
$loginError = '';
$actionMessage = '';
$actionError = '';

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    $password = $_POST['password'] ?? '';
    if ($password === ADMIN_PASSWORD) {
        $_SESSION['admin_logged_in'] = true;
        $isLoggedIn = true;
    } else {
        $loginError = 'Invalid password. Please try again.';
    }
}

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

// ---- Router CRUD (requires auth) ----
if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name     = trim($_POST['name'] ?? '');
        $ip       = trim($_POST['ip'] ?? '');
        $port     = (int) ($_POST['port'] ?? ROUTEROS_DEFAULT_PORT);
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password_field'] ?? '';

        if ($name && $ip && $username && $password) {
            if ($port < 1 || $port > 65535) $port = ROUTEROS_DEFAULT_PORT;
            addRouter($name, $ip, $port, $username, $password);
            $actionMessage = 'Router "' . htmlspecialchars($name) . '" added successfully.';
        } else {
            $actionError = 'All fields are required.';
        }
    }

    if ($action === 'edit') {
        $id       = (int) ($_POST['id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $ip       = trim($_POST['ip'] ?? '');
        $port     = (int) ($_POST['port'] ?? ROUTEROS_DEFAULT_PORT);
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password_field'] ?? '';

        if ($id > 0 && $name && $ip && $username && $password) {
            if ($port < 1 || $port > 65535) $port = ROUTEROS_DEFAULT_PORT;
            updateRouter($id, $name, $ip, $port, $username, $password);
            $actionMessage = 'Router "' . htmlspecialchars($name) . '" updated successfully.';
        } else {
            $actionError = 'All fields are required.';
        }
    }

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $router = getRouter($id);
            deleteRouter($id);
            $actionMessage = 'Router deleted successfully.';
        }
    }
}

// Fetch routers for display
$routers = $isLoggedIn ? getAllRouters() : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - VoucherReset</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <canvas id="net-bg"></canvas>
    <div class="bg-veil"></div>
    <div class="app-wrapper">

        <!-- Header -->
        <header class="app-header">
            <div class="container">
                <a href="index.php" class="brand">
                    <div class="brand-icon">V</div>
                    <div>
                        <div class="brand-text"><span>Voucher</span>Reset</div>
                        <div class="tagline">Admin Panel</div>
                    </div>
                </a>
            </div>
        </header>

        <main>
            <?php if (!$isLoggedIn): ?>

            <!-- Login Form -->
            <div class="container">
                <div class="login-wrapper">
                    <div class="login-card">
                        <h2>Admin Login</h2>
                        <?php if ($loginError): ?>
                            <div class="error-msg"><?= htmlspecialchars($loginError) ?></div>
                        <?php endif; ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="login">
                            <div class="form-group">
                                <label for="password">Password</label>
                                <input type="password" id="password" name="password" placeholder="Enter admin password" required autofocus>
                            </div>
                            <button type="submit" class="btn btn-primary">Login</button>
                        </form>
                    </div>
                </div>
            </div>

            <?php else: ?>

            <!-- Admin Dashboard -->
            <div class="container admin-container">

                <?php if ($actionMessage): ?>
                    <div style="background: rgba(0,214,143,0.1); border: 1px solid rgba(0,214,143,0.3); color: var(--success); padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">
                        <?= $actionMessage ?>
                    </div>
                <?php endif; ?>

                <?php if ($actionError): ?>
                    <div style="background: rgba(255,71,87,0.1); border: 1px solid rgba(255,71,87,0.3); color: var(--danger); padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;">
                        <?= htmlspecialchars($actionError) ?>
                    </div>
                <?php endif; ?>

                <div class="admin-header">
                    <h2>Router Management</h2>
                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                        <button class="btn btn-add btn-sm" onclick="openModal('add')">+ Add Router</button>
                        <a href="admin.php?logout=1" class="btn btn-logout btn-sm">Logout</a>
                    </div>
                </div>

                <?php if (empty($routers)): ?>
                    <div class="empty-state">
                        <div class="empty-icon">&#128225;</div>
                        <p>No routers configured yet.<br>Click "Add Router" to get started.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>IP Address</th>
                                    <th>Port</th>
                                    <th>Username</th>
                                    <th>Password</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($routers as $i => $r): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><strong><?= htmlspecialchars($r['name']) ?></strong></td>
                                    <td><?= htmlspecialchars($r['ip']) ?></td>
                                    <td><?= (int) $r['port'] ?></td>
                                    <td><?= htmlspecialchars($r['username']) ?></td>
                                    <td><span class="password-masked">********</span></td>
                                    <td>
                                        <div class="actions">
                                            <button class="btn btn-edit btn-sm"
                                                onclick="openModal('edit', <?= htmlspecialchars(json_encode($r)) ?>)">
                                                Edit
                                            </button>
                                            <form method="POST" style="display:inline;"
                                                  onsubmit="return confirm('Delete router &quot;<?= htmlspecialchars(addslashes($r['name'])) ?>&quot;? This cannot be undone.')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                                <button type="submit" class="btn btn-delete btn-sm">Delete</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>

            </div>

            <!-- Modal for Add/Edit -->
            <div id="modal-overlay" class="modal-overlay" onclick="if(event.target===this)closeModal()">
                <div class="modal">
                    <h3 id="modal-title">Add Router</h3>
                    <form id="modal-form" method="POST">
                        <input type="hidden" name="action" id="modal-action" value="add">
                        <input type="hidden" name="id" id="modal-id" value="">

                        <div class="form-group">
                            <label for="modal-name">Router Name</label>
                            <input type="text" id="modal-name" name="name" placeholder="e.g. Mikrotik 1" required>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="modal-ip">IP Address</label>
                                <input type="text" id="modal-ip" name="ip" placeholder="e.g. 192.168.1.1" required>
                            </div>
                            <div class="form-group">
                                <label for="modal-port">API Port</label>
                                <input type="number" id="modal-port" name="port" value="8728" min="1" max="65535" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="modal-username">API Username</label>
                                <input type="text" id="modal-username" name="username" placeholder="e.g. admin" required>
                            </div>
                            <div class="form-group">
                                <label for="modal-password">API Password</label>
                                <input type="text" id="modal-password" name="password_field" placeholder="Password" required>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                            <button type="submit" class="btn btn-primary" id="modal-submit-btn">Save Router</button>
                        </div>
                    </form>
                </div>
            </div>

            <script>
                function openModal(mode, data) {
                    var overlay = document.getElementById('modal-overlay');
                    var title = document.getElementById('modal-title');
                    var action = document.getElementById('modal-action');
                    var idField = document.getElementById('modal-id');
                    var nameField = document.getElementById('modal-name');
                    var ipField = document.getElementById('modal-ip');
                    var portField = document.getElementById('modal-port');
                    var usernameField = document.getElementById('modal-username');
                    var passwordField = document.getElementById('modal-password');
                    var submitBtn = document.getElementById('modal-submit-btn');

                    if (mode === 'edit' && data) {
                        title.textContent = 'Edit Router';
                        action.value = 'edit';
                        idField.value = data.id;
                        nameField.value = data.name;
                        ipField.value = data.ip;
                        portField.value = data.port;
                        usernameField.value = data.username;
                        passwordField.value = data.password;
                        submitBtn.textContent = 'Update Router';
                    } else {
                        title.textContent = 'Add Router';
                        action.value = 'add';
                        idField.value = '';
                        nameField.value = '';
                        ipField.value = '';
                        portField.value = '8728';
                        usernameField.value = '';
                        passwordField.value = '';
                        submitBtn.textContent = 'Add Router';
                    }

                    overlay.classList.add('visible');
                    nameField.focus();
                }

                function closeModal() {
                    document.getElementById('modal-overlay').classList.remove('visible');
                }

                // Close modal on Escape
                document.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') closeModal();
                });
            </script>

            <?php endif; ?>
        </main>

        <!-- Footer -->
        <footer class="app-footer">
            <div class="container">
                Managed by <strong>Mikrotik97</strong>
            </div>
        </footer>

    </div>
    <script src="assets/js/network-bg.js"></script>
</body>
</html>
