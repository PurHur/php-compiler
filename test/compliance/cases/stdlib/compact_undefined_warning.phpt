--TEST--
stdlib compact() — E_WARNING for undefined variable names (issue #3750)
--FILE--
<?php
function compact_warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
$a = 1;
set_error_handler('compact_warn_capture');
$c = compact('a', 'b');
var_dump($c);
--EXPECT--
W:compact(): Undefined variable $b
array(1) {
  ["a"]=>
  int(1)
}
