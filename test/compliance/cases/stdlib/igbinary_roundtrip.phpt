--TEST--
stdlib igbinary_serialize round-trip (#6573)
--INI--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$data = ['k' => 1, 'nested' => [true, 'x']];
$bin = igbinary_serialize($data);
echo igbinary_unserialize($bin) === $data ? "ok\n" : "fail\n";
--EXPECT--
ok
