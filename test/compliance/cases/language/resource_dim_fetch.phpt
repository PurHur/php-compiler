--TEST--
Language: resource as array subject — Warning on read, scalar Error on write, soft isset/empty (#30028)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

$f = fopen('php://memory', 'r');
try {
    var_export($f[0]);
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $f[0] = 1;
    echo "write ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
var_export(isset($f[0]));
echo "\n";
var_export(empty($f[0]));
echo "\n";
fclose($f);
--EXPECTF--
W:Trying to access array offset on %Sresource
NULL
Error:Cannot use a scalar value as an array
false
true
