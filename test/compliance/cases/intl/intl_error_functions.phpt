--TEST--
intl error introspection — idle state + intl_is_failure + intl_error_name (#5156, #20872, ext/intl/intl_error.c)
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
echo function_exists('intl_error_name') ? "name_fn\n" : "no_name\n";

echo intl_get_error_code(), "\n";
echo intl_get_error_message(), "\n";
echo intl_is_failure(intl_get_error_code()) ? "fail\n" : "ok\n";
echo intl_is_failure(-1) ? "yes\n" : "no\n";
echo intl_error_name(0), "\n";
echo intl_error_name(1), "\n";
echo intl_error_name(-128), "\n";
?>
--EXPECT--
code_fn
exists
failure_fn
name_fn
0
U_ZERO_ERROR
ok
yes
U_ZERO_ERROR
U_ILLEGAL_ARGUMENT_ERROR
U_USING_FALLBACK_WARNING
