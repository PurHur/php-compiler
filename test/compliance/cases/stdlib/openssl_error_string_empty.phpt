--TEST--
stdlib openssl_error_string() — empty queue returns false (issue #6559)
--SKIPIF--
<?php
if (!function_exists('openssl_error_string')) {
    die('skip openssl_error_string missing');
}
?>
--FILE--
<?php
var_export(openssl_error_string());
echo "\n";
--EXPECT--
false
