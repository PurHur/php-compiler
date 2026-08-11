--TEST--
Language: PROFILE≥8.3 array offset on scalar — short Warning + true/false (#30053)
--ENV--
PHP_COMPILER_PROFILE=8.4
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

read_scalar('false', function () {
    $x = false;
    $x[0];
});
read_scalar('true', function () {
    $x = true;
    $x[0];
});
read_scalar('int', function () {
    $x = 1;
    $x[0];
});
read_scalar('float', function () {
    $x = 1.5;
    $x[0];
});
read_scalar('null', function () {
    $x = null;
    $x[0];
});
--EXPECT--
W:Trying to access array offset on false
false: NULL
W:Trying to access array offset on true
true: NULL
W:Trying to access array offset on int
int: NULL
W:Trying to access array offset on float
float: NULL
W:Trying to access array offset on null
null: NULL
