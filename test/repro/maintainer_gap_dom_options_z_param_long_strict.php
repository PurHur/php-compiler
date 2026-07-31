<?php
/**
 * #25768 — strict_types rejects non-int DOM options like Zend.
 */
declare(strict_types=1);

$doc = new DOMDocument();
try {
    $doc->loadHTML('<p>x</p>', '0');
    echo "strict_str=ok\n";
} catch (Throwable $e) {
    echo 'strict_str=', $e->getMessage(), "\n";
}
try {
    $doc->loadHTML('<p>x</p>', 0);
    echo "strict_int=ok\n";
} catch (Throwable $e) {
    echo 'strict_int=', $e->getMessage(), "\n";
}
