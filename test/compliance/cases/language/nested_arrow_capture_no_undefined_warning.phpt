--TEST--
Language: nested arrow capture — no spurious undefined variable E_WARNING on outer parameter (#10304, #10358)
--FILE--
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";

    return true;
}
set_error_handler('warn_capture');

$f = fn (int $n) => fn () => $n * 2;
echo $f(3)(), "\n";

$x = 1;
$af = fn () => $x;
echo $af(), "\n";
--EXPECT--
6
1
