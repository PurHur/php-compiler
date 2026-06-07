--TEST--
Language: never in parameter union — rejects incompatible values (#7414)
--FILE--
<?php
function f(int|never $x): void {}
try {
    f('bad');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: Argument must be of type int|never, string given
