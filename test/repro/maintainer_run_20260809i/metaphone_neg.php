<?php
// Repro #29304 — metaphone() negative $max_phonemes → Zend ValueError (php-src-strict).
error_reporting(E_ALL);
try {
    metaphone('test', -1);
    echo "NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
