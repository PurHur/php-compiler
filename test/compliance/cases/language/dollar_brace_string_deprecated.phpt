--TEST--
Language: "${var}" dollar-brace interpolation emits E_DEPRECATED (Zend/zend_compile.c, #22001)
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

$foo = 'bar';
eval('echo "${foo}\n";');
$ok = 1 === count($seen)
    && str_contains($seen[0], 'Using ${var} in strings is deprecated')
    && str_contains($seen[0], 'use {$var} instead');
echo $ok ? "dollar_brace_ok\n" : "dollar_brace_bad\n";

eval('echo "$foo\n";');
eval('echo "{$foo}\n";');
echo 'count=', count($seen), "\n";

eval('echo "${foo}${foo}\n";');
echo 'count2=', count($seen), "\n";
--EXPECT--
bar
dollar_brace_ok
bar
bar
count=1
barbar
count2=3
