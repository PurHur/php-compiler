<?php
/**
 * Repro #27550 — AOT date_default_timezone_set + strtotime must not abort
 * ("Current basic block has no parent function").
 *
 *   PHP_COMPILER_HELPER_RUNTIME_O=0 php bin/compile.php -o /tmp/st \
 *     test/repro/issue_27550_aot_strtotime_after_timezone_set.php
 *   /tmp/st
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(strtotime) / date_default_timezone_set
 */
date_default_timezone_set('UTC');
echo strtotime('2020-01-15'), "\n";
