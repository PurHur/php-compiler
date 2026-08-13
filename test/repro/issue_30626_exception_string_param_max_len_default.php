<?php
// Repro #30626 — bare VM (no host/guest -d) must keep php-src compiled default 15
// for zend.exception_string_param_max_len (`php -n`), so UnhandledMatchError shows
// string subjects instead of always redacting to '...'.
echo 'max=', ini_get('zend.exception_string_param_max_len'), "\n";
try {
    echo match('hello') { 'a' => 1 };
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
