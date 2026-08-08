--TEST--
stdlib sprintf() %a/%A unknown — Zend ValueError (#29085/#29059, retract #9059)
--FILE--
<?php
foreach (['%a', '%A'] as $fmt) {
    try {
        echo sprintf($fmt, 1.5), "\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
try {
    echo printf('%a', 1.5), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    echo vsprintf('%A', [1.5]), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
ValueError:Unknown format specifier "a"
ValueError:Unknown format specifier "A"
ValueError:Unknown format specifier "a"
ValueError:Unknown format specifier "A"
