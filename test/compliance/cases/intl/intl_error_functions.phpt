--TEST--
intl error introspection — idle state + intl_is_failure (#5156, ext/intl/intl_error.c)
--SKIPIF--
<?php
if (!function_exists('intl_get_error_message')) {
    die('skip intl error functions not advertised');
}
?>
--FILE--
<?php
declare(strict_types=1);

echo function_exists('intl_get_error_code') ? "code_fn\n" : "no_code\n";
echo function_exists('intl_get_error_message') ? "exists\n" : "missing\n";
echo function_exists('intl_is_failure') ? "failure_fn\n" : "no_failure\n";

echo intl_get_error_code(), "\n";
echo intl_get_error_message(), "\n";
echo intl_is_failure(intl_get_error_code()) ? "fail\n" : "ok\n";
echo intl_is_failure(-1) ? "yes\n" : "no\n";
?>
--EXPECT--
code_fn
exists
failure_fn
0

ok
yes
