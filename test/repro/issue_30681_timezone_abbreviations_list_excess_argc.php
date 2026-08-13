<?php
/**
 * timezone_abbreviations_list excess argc → ArgumentCountError (#30681).
 * php-src: ext/date/php_date.c
 */
try {
    timezone_abbreviations_list(1);
    echo "excess:OK\n";
} catch (ArgumentCountError $e) {
    echo 'excess:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'excess:', get_class($e), ':', $e->getMessage(), "\n";
}

$r = timezone_abbreviations_list();
echo 'ok_type:', gettype($r), "\n";
echo 'ok_nonempty:', (is_array($r) && count($r) > 0) ? '1' : '0', "\n";
