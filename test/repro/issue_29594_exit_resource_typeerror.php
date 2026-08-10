<?php
/** Repro #29594 — exit(resource) TypeError uses lowercase "resource". */
error_reporting(E_ALL);
ini_set('display_errors', '1');
$r = fopen('php://memory', 'r');
try {
    exit($r);
} catch (Throwable $t) {
    echo get_class($t), ': ', $t->getMessage(), "\n";
}
