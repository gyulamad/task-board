<?php
// Copy this file to config.php and enter your database credentials.
return [
    'db_host' => 'localhost',
    'db_name' => 'YOUR_DATABASE',
    'db_user' => 'YOUR_DATABASE_USER',
    'db_pass' => 'YOUR_DATABASE_PASSWORD',
    'app_name' => 'Task Board',
    'max_upload_bytes' => 5 * 1024 * 1024, // 5 MB
];

// Optional (#108): only for hosts whose DATABASE timezone CHANGED after old rows
// were written. The one-time datetime -> timestamp conversion reads old strings in
// the server's own clock, which is correct while the server timezone stays put;
// set a fixed offset like '+02:00' here to re-interpret the legacy strings instead.
// Default: unset (the server's own clock, UTC on typical shared hosting).
// 'legacy_db_timezone' => '+02:00',
