<?php
/**
 * $s[] = must Error with Zend message (php-src Zend/zend_execute.c).
 * Expected Zend: [] operator not supported for strings
 */
$s = 'a';
try {
    $s[] = 'b';
    echo "NO_THROW\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
