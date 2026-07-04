--TEST--
stdlib file_put_contents() string $flags — TypeError not LogicException (#9285, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);

try {
    file_put_contents(sys_get_temp_dir() . '/phpc_flags_test.txt', 'x', 'LOCK_EX');
    echo "fail: no throw\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
file_put_contents(): Argument #3 ($flags) must be of type int, string given
