--TEST--
stdlib msgpack_unpack invalid payload returns false with warning (#6551, ext/msgpack/msgpack.c)
--FILE--
<?php
declare(strict_types=1);

$result = @msgpack_unpack("\xff");
echo $result === false ? '1' : '0';
echo "\n";
?>
--EXPECT--
1
