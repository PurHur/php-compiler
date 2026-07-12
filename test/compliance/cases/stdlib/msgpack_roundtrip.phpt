--TEST--
stdlib msgpack_pack/msgpack_unpack round-trip (#6551, ext/msgpack/msgpack.c)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsMsgpack()) {
    die('skip msgpack withheld on reference profile (#17994)');
}
--FILE--
<?php
declare(strict_types=1);

$data = ['a' => 1, 'b' => [2, 3], 'c' => 'hello', 'd' => true, 'e' => null, 'f' => 1.5];
$packed = msgpack_pack($data);
$unpacked = msgpack_unpack($packed);
echo is_string($packed) ? '1' : '0';
echo $unpacked === $data ? '1' : '0';
echo function_exists('msgpack_pack') ? '1' : '0';
echo function_exists('msgpack_unpack') ? '1' : '0';
echo extension_loaded('msgpack') ? '1' : '0';
echo "\n";
?>
--EXPECT--
11111
