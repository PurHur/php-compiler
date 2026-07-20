<?php
// Repro #21280 — crypt(null, …) / crypt(…, null) soft-null under PROFILE=8.4 (Zend DEP+coerce)

error_reporting(E_ALL & ~E_DEPRECATED);

try {
    $result = crypt('password', null);
    echo is_string($result) ? "OK: salt-null coerced\n" : "FAIL\n";
} catch (\TypeError $e) {
    echo "FAIL: TypeError — " . $e->getMessage() . "\n";
    exit(1);
}

try {
    $result2 = crypt(null, '$2y$10$abcdefghijklmnopqrstuv');
    echo is_string($result2) ? "OK: string-null coerced\n" : "FAIL\n";
} catch (\TypeError $e) {
    echo "FAIL: TypeError (password) — " . $e->getMessage() . "\n";
    exit(1);
}

echo "PASS\n";
