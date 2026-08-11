--TEST--
Language: array offset on scalar — E_WARNING and null (zend_execute.c, #4867)
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

read_scalar('int', function () {
    $x = 1;
    $x[0];
});
read_scalar('null', function () {
    $x = null;
    $x[0];
});
read_scalar('bool', function () {
    $x = true;
    $x[0];
});
read_scalar('float', function () {
    $x = 1.5;
    $x[0];
});
try {
    class C
    {
    }
    $o = new C();
    $o[0];
} catch (Error $e) {
    echo 'object: Error: ', $e->getMessage(), "\n";
}
--EXPECT--
W:Trying to access array offset on int
int: NULL
W:Trying to access array offset on null
null: NULL
W:Trying to access array offset on true
bool: NULL
W:Trying to access array offset on float
float: NULL
object: Error: Cannot use object of type C as array
