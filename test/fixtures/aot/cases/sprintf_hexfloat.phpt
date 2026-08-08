--TEST--
AOT: sprintf() %a/%A unknown ValueError (#29085, retract #9059)
--FILE--
<?php
try {
    echo sprintf('%a', 3.14159), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo sprintf('%A', 3.14159), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
ValueError:Unknown format specifier "a"
ValueError:Unknown format specifier "A"
