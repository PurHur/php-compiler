--TEST--
stdlib compact() — non-string var_names warn-and-continue (issue #4487)
--FILE--
<?php
function compact_warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
$a = 1;
set_error_handler('compact_warn_capture');
$c = compact(['a', 123]);
var_dump($c);
--EXPECT--
W:compact(): Argument #1 must be string or array of strings, int given
array(1) {
  ["a"]=>
  int(1)
}
