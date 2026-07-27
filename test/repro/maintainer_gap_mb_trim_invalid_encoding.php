<?php
/**
 * PROFILE=8.4: invalid encoding must ValueError like Zend mbstring, not LogicException.
 */
error_reporting(E_ALL);
foreach (['mb_trim', 'mb_ltrim', 'mb_rtrim'] as $fn) {
    try {
        $fn('x', null, 'not-an-encoding');
        echo "$fn=OK\n";
    } catch (Throwable $e) {
        echo "$fn=", $e::class, ':', $e->getMessage(), "\n";
    }
}
