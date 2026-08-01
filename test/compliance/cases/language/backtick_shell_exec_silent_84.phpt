--TEST--
Language: backtick shell-exec silent under PROFILE=8.4 (#26280)
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
$out = null;
eval('$out = `true`;');
echo 'warns=', count($seen), "\n";
echo 'result_ok=', (is_string($out) || null === $out) ? '1' : '0', "\n";
--EXPECT--
warns=0
result_ok=1
