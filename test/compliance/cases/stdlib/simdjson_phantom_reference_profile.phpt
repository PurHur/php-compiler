--TEST--
stdlib simdjson withheld on reference profile (#22530, PECL simdjson)
--SKIPIF--
<?php
$raw = getenv('PHP_COMPILER_PROFILE');
if (\is_string($raw) && '' !== trim($raw) && version_compare(trim($raw).'.0', '8.4.0', '>=')) {
    die('skip forward profile enables simdjson');
}
--FILE--
<?php
declare(strict_types=1);

$phantom = extension_loaded('simdjson')
    || function_exists('simdjson_decode')
    || function_exists('simdjson_is_valid');
echo $phantom ? "fail\n" : "ok\n";
--EXPECT--
ok
