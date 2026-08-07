<?php
/**
 * Missing RoundingMode under default profile — Zend catchable Error (#28480).
 * PROFILE≥8.4 registers the enum; this script asserts the absent-class path only.
 */
echo 'class_exists=', class_exists('RoundingMode') ? 'Y' : 'N', PHP_EOL;
try {
    $x = RoundingMode::HalfAwayFromZero;
    echo "unexpected_ok", PHP_EOL;
} catch (Error $e) {
    echo 'caught:', get_class($e), ':', $e->getMessage(), PHP_EOL;
}
echo "after", PHP_EOL;
