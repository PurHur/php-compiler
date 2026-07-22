--TEST--
stdlib compact() — E_WARNING after unset() omits key (issue #21940, ext/standard/array.c)
--FILE--
<?php
function compact_warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
$u = 1;
unset($u);
set_error_handler('compact_warn_capture');
$c = compact('u');
var_dump($c);
--EXPECT--
W:compact(): Undefined variable $u
array(0) {
}
