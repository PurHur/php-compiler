--TEST--
JIT: glob() — invalid flag bitmask E_WARNING + false (#16970)
--FILE--
<?php
error_reporting(E_ALL);
$count = 0;
set_error_handler(static function () use (&$count): bool {
    ++$count;
    return true;
});
$result = @glob('*', 99999);
echo 'count=' . $count . "\n";
echo $result === false ? "false\n" : "not_false\n";
--EXPECT--
count=1
false
