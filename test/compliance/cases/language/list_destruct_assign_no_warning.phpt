--TEST--
Language: list()/[] destructuring — no spurious E_WARNING on assign targets (#10591, zend_execute.c)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

list($e) = $s = 'x';
var_export($e);
echo "\n";
[[$f]] = 'y';
var_export($f);
echo "\n";
list($a, $b) = 'ab';
var_export([$a, $b]);
echo "\n";
--EXPECT--
NULL
NULL
array (
  0 => NULL,
  1 => NULL,
)
