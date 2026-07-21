<?php
/**
 * VoucherReset - Configuration
 */

// App settings
define('APP_NAME', 'VoucherReset');
define('APP_VERSION', '1.0.0');

// Initial/default admin password.
// Used only for the FIRST login. Once you change the password from the
// admin panel (Change Password button), the new password is stored securely
// (bcrypt-hashed) in the database and this value is no longer used.
define('ADMIN_PASSWORD', 'admin123');

// Database
define('DB_PATH', __DIR__ . '/../data/voucherreset.db');

// RouterOS API defaults
define('ROUTEROS_DEFAULT_PORT', 8728);
define('ROUTEROS_TIMEOUT', 3);
define('ROUTEROS_ATTEMPTS', 1);

// Session name
define('SESSION_NAME', 'voucherreset_session');
