--TEST--
Language: array dim fetch read on null/bool — E_WARNING (#4867; zend_execute.c)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

function read_scalar(string $label, callable $fn): void
{
    $fn();
    echo $label, ": NULL\n";
}

read_scalar('read_null', function () {
    $x = null;
    $x[0];
});
read_scalar('read_false', function () {
    $x = false;
    $x[0];
});
--EXPECT--
W:Trying to access array offset on null
read_null: NULL
W:Trying to access array offset on false
read_false: NULL
