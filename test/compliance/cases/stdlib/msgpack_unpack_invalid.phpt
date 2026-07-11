--TEST--
stdlib msgpack_unpack invalid payload returns false with warning (#6551, ext/msgpack/msgpack.c)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsMsgpack()) {
    die('skip msgpack withheld on reference profile (#17994)');
}
--FILE--
<?php
declare(strict_types=1);

$result = @msgpack_unpack("\xff");
echo $result === false ? '1' : '0';
echo "\n";
?>
--EXPECT--
1
