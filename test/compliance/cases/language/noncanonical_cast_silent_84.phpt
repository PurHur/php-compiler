--TEST--
Language: non-canonical casts silent under PROFILE=8.4 (#26281)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $errno, string $msg) use (&$seen): bool {
    if (E_DEPRECATED === $errno) {
        $seen[] = $msg;
    }
    return true;
});
eval('$v = (integer)1.5; echo "val=$v\n";');
echo 'warns=', count($seen), "\n";
--EXPECT--
val=1
warns=0
