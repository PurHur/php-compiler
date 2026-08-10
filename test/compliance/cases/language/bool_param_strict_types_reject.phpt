--TEST--
Language: strict_types still TypeErrors string/int→bool (#29860)
--FILE--
<?php
declare(strict_types=1);

function f(bool $x): bool
{
    return $x;
}

try {
    f('x');
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}

try {
    f(1);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
TypeError
TypeError
