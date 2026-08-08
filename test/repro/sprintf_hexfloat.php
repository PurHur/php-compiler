<?php
// #29085 / #29059 — Zend rejects %a/%A (not PHP sprintf conversions).
try {
    echo sprintf('%a', 3.14159), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo sprintf('%A', 3.14159), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
