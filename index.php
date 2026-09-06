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

                    <div class="voucher-card">
                        <form id="reset-form" autocomplete="off">

                            <!-- Step 1: Select Router -->
                            <div class="form-group">
                                <label for="router-select">Select Router</label>
                                <div class="select-wrapper">
                                    <select id="router-select" name="router_id" required>
                                        <option value="" disabled selected>Choose a router...</option>
                                        <?php foreach ($routers as $router): ?>
                                            <option value="<?= htmlspecialchars($router['id']) ?>"
                                                    data-name="<?= htmlspecialchars($router['name']) ?>">
                                                <?= htmlspecialchars($router['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span id="router-status-indicator" class="select-status"></span>
                                </div>
                            </div>

                            <!-- Step 2: Voucher Code -->
                            <div class="form-group">
                                <label for="voucher-input">Voucher Code</label>
                                <input type="text"
                                       id="voucher-input"
                                       name="voucher"
                                       placeholder="Enter hotspot voucher"
                                       required
                                       autocomplete="off"
                                       spellcheck="false">
                            </div>

                            <div class="btn-row">
                                <button type="button" id="check-btn" class="btn btn-secondary">
                                    Check Voucher
                                </button>
                                <button type="submit" id="reset-btn" class="btn btn-primary">
                                    Reset Now
                                </button>
                            </div>
                            <p class="btn-hint">Check first to see the expiry date and what is left - it changes nothing on the router.</p>
                        </form>
                    </div>

                    <!-- Voucher details (hidden until Check Voucher is pressed) -->
                    <div id="check-wrapper" class="check-wrapper">
                        <div id="check-card" class="check-card"></div>
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

    <script src="assets/js/network-bg.js"></script>
    <script src="assets/js/app.js"></script>
</body>
</html>
