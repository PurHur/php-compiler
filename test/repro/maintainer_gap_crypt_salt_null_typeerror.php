<?php

// Issue #18657 — crypt(null salt) must throw TypeError, not return '*0'

$caught = false;
try {
    $result = crypt('password', null);
    echo "FAIL: no TypeError, got: " . var_export($result, true) . "\n";
} catch (\TypeError $e) {
    $caught = true;
    echo "OK: TypeError — " . $e->getMessage() . "\n";
}

if (!$caught) {
    exit(1);
}

// Also test null password
$caught2 = false;
try {
    $result2 = crypt(null, '$2y$10$abcdefghijklmnopqrstuv');
    echo "FAIL: no TypeError for null password, got: " . var_export($result2, true) . "\n";
} catch (\TypeError $e) {
    $caught2 = true;
    echo "OK: TypeError (password) — " . $e->getMessage() . "\n";
}

if (!$caught2) {
    exit(1);
}

echo "PASS\n";
