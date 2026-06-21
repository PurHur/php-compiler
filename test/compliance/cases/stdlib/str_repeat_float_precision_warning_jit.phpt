--TEST--
stdlib str_repeat() JIT — float $times precision E_WARNING (issue #10440, ext/standard/string.c)
--JIT--
--FILE--
<?php
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
$result = str_repeat('x', 2.9);
restore_error_handler();
echo $result, "\n", 'warnings=', count($warnings), "\n";
if ($warnings) {
    echo $warnings[0], "\n";
}
--EXPECT--
xx
warnings=1
Implicit conversion from float 2.9 to int loses precision
