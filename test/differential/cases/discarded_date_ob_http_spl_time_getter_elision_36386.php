<?php
// Discarded timezone_*_list / ob_list_handlers / date_get_last_errors /
// spl_autoload_functions / time / error_reporting / ignore_user_abort /
// http_response_code / headers_sent must match Zend (#36386). Side-effect-free
// statements only. Live shape checks use a minimal AOT-safe set.
// (http_get_last_response_headers is PHP 8.4+; covered in unit elision only.)
// php-src: ext/date/php_date.c, ext/standard/output.c, ext/spl/php_spl.c,
// ext/standard/basic_functions.c, ext/standard/head.c
// @differential-repeat: 3
function work(int $loops): int
{
    $c = 0;
    for ($k = 0; $k < $loops; ++$k) {
        timezone_abbreviations_list();
        timezone_identifiers_list();
        ob_list_handlers();
        date_get_last_errors();
        spl_autoload_functions();
        time();
        error_reporting();
        ignore_user_abort();
        http_response_code();
        headers_sent();
        $c += $k;
    }

    return $c;
}
echo work(5), "\n";
echo work(3), "\n";
echo work(2), "\n";

$t = time();
$er = error_reporting();
$hs = headers_sent();
$rc = http_response_code();
echo is_int($t) && $t > 0 ? "1" : "0", "\n";
echo is_int($er) ? "1" : "0", "\n";
echo is_bool($hs) ? "1" : "0", "\n";
echo (false === $rc || is_int($rc)) ? "1" : "0", "\n";
