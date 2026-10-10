<?php
// Copy this file to config.php (next to it, outside public/) and fill in your
// MySQL details from cPanel > MySQL Databases. Never commit config.php.
return [
    'db_host' => 'localhost',
    'db_name' => 'cpaneluser_unsaid',
    'db_user' => 'cpaneluser_game',
    'db_pass' => 'change-me',
    // The admin page. Leave the password empty to turn it off.
    'admin_password' => '',
    // The admin page's address: https://your-site/<admin_path>. Pick something nobody
    // would guess (4-64 letters, numbers, - or _). Every other address says "Not found".
    'admin_path' => 'admin',
];
