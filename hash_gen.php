<?php
$hashes = [
    'admin123' => password_hash('admin123', PASSWORD_DEFAULT),
    'registrar123' => password_hash('registrar123', PASSWORD_DEFAULT),
    'dean123' => password_hash('dean123', PASSWORD_DEFAULT),
    'osa123' => password_hash('osa123', PASSWORD_DEFAULT),
    'accounting123' => password_hash('accounting123', PASSWORD_DEFAULT),
    'library123' => password_hash('library123', PASSWORD_DEFAULT),
    'student123' => password_hash('student123', PASSWORD_DEFAULT),
];

header('Content-Type: text/plain');

foreach ($hashes as $pwd => $hash) {
    echo "=== $pwd ===\n$hash\n\n";
}