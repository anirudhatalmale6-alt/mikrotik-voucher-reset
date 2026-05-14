<?php
/**
 * VoucherReset - Public Homepage
 */
require_once __DIR__ . '/includes/db.php';

$routers = getAllRouters();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VoucherReset - Hotspot Session Reset</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="app-wrapper">

        <!-- Header -->
        <header class="app-header">
            <div class="container">
                <a href="index.php" class="brand">
                    <div class="brand-icon">V</div>
                    <div>
                        <div class="brand-text"><span>Voucher</span>Reset</div>
                        <div class="tagline">MikroTik Hotspot Session Reset</div>
                    </div>
                </a>
            </div>
        </header>

        <!-- Main Content -->
        <main>
            <div class="container">

                <?php if (empty($routers)): ?>

                    <!-- Empty state -->
                    <div class="empty-state">
                        <div class="empty-icon">&#128225;</div>
                        <p>No routers have been configured yet.<br>Please contact the administrator.</p>
                    </div>

                <?php else: ?>

                    <!-- Step 1: Select Router -->
                    <p class="section-title">Select Your Router</p>

                    <div id="router-grid" class="router-grid">
                        <?php foreach ($routers as $router): ?>
                            <div class="router-card"
                                 data-id="<?= htmlspecialchars($router['id']) ?>"
                                 data-name="<?= htmlspecialchars($router['name']) ?>">
                                <span class="status-dot checking"></span>
                                <span class="router-name"><?= htmlspecialchars($router['name']) ?></span>
                                <span class="router-status-label">Checking...</span>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Step 2: Voucher Form (hidden until router selected) -->
                    <div id="voucher-form-wrapper" class="voucher-form-wrapper">
                        <div class="voucher-card">
                            <div class="selected-router">
                                <span>Router:</span>
                                <strong id="selected-router-name"></strong>
                                <button type="button" class="change-btn" id="change-router-btn">Change</button>
                            </div>

                            <form id="reset-form" autocomplete="off">
                                <div class="form-group">
                                    <label for="voucher-input">Enter your voucher code</label>
                                    <input type="text"
                                           id="voucher-input"
                                           name="voucher"
                                           placeholder="e.g. user123 or V-ABC-1234"
                                           required
                                           autocomplete="off"
                                           spellcheck="false">
                                </div>
                                <button type="submit" id="reset-btn" class="btn btn-primary">
                                    Reset Voucher
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Step 3: Result (hidden until reset completes) -->
                    <div id="result-wrapper" class="result-wrapper">
                        <div id="result-card" class="result-card">
                            <div id="result-icon" class="result-icon"></div>
                            <div id="result-title" class="result-title"></div>
                            <div id="result-message" class="result-message"></div>
                            <button type="button" id="try-again-btn" class="btn btn-secondary">
                                Try Another Voucher
                            </button>
                        </div>
                    </div>

                <?php endif; ?>

            </div>
        </main>

        <!-- Footer -->
        <footer class="app-footer">
            <div class="container">
                Managed by <strong>Mikrotik97</strong>
            </div>
        </footer>

    </div>

    <script src="assets/js/app.js"></script>
</body>
</html>
