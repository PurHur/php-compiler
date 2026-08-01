--TEST--
Language: switch case semicolon silent under PROFILE=8.4 (#26279)
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
eval('switch (1) { case 1; echo "hit\n"; break; }');
echo 'warns=', count($seen), "\n";
--EXPECT--
hit
warns=0
