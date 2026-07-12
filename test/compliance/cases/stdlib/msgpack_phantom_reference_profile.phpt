--TEST--
stdlib msgpack withheld on reference profile — extension_loaded false (#17994, ext/msgpack/msgpack.c)
--SKIPIF--
<?php
$raw = getenv('PHP_COMPILER_PROFILE');
if (\is_string($raw) && '' !== trim($raw) && version_compare(trim($raw).'.0', '8.4.0', '>=')) {
    die('skip forward profile enables msgpack');
}
--FILE--
<?php
declare(strict_types=1);

$phantom = extension_loaded('msgpack')
    || function_exists('msgpack_pack')
    || function_exists('msgpack_unpack');
echo $phantom ? "fail\n" : "ok\n";
--EXPECT--
ok
