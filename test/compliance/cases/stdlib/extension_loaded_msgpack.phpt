--TEST--
stdlib extension_loaded('msgpack') true when pack/unpack implemented (#6551, ext/msgpack/msgpack.c)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsMsgpack()) {
    die('skip msgpack withheld on reference profile (#17994)');
}
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('msgpack'), "\n";
echo 'in_list=', (int) in_array('msgpack', get_loaded_extensions(), true), "\n";
echo 'pack=', (int) function_exists('msgpack_pack'), "\n";
echo 'unpack=', (int) function_exists('msgpack_unpack'), "\n";
--EXPECT--
loaded=1
in_list=1
pack=1
unpack=1
