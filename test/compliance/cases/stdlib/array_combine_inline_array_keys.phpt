--TEST--
stdlib array_combine() inline array_keys() haystack — nested builtin materializes (#15440, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
$expected = ['a' => 10, 'b' => 20];
$inline = array_combine(array_keys(['a' => 1, 'b' => 2]), [10, 20]);
echo $inline === $expected ? "ok\n" : "fail\n";
--EXPECT--
ok
