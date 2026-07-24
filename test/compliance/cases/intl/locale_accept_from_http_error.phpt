--TEST--
Locale::acceptFromHttp() failure sets U_ILLEGAL_ARGUMENT_ERROR (#22853)
--FILE--
<?php
declare(strict_types=1);
// Soft-exit: BaseTest ignores --SKIPIF-- (see tidy_repair_host.phpt / #22691).
if (!class_exists('Locale', false) || !function_exists('intl_get_error_code')) {
    echo "skip\n";
    exit(0);
}
$r = Locale::acceptFromHttp('!!!invalid!!!');
var_export($r);
echo "\n";
echo intl_get_error_code(), "\n";
echo intl_get_error_message(), "\n";
echo intl_is_failure(intl_get_error_code()) ? "fail\n" : "ok\n";

$ok = Locale::acceptFromHttp('en-US,en;q=0.5');
var_export($ok);
echo "\n";
echo intl_is_failure(intl_get_error_code()) ? "fail\n" : "ok\n";
?>
--EXPECTF--
%a
