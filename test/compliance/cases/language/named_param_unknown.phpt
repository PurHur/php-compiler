--TEST--
Unknown named parameter throws Error catchable by catch (Error) (#4300)
--FILE--
<?php
function g(int $a): void {}

try {
    g(notaparam: 1);
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    g(alsobad: 2);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
Error: Unknown named parameter $notaparam
Error: Unknown named parameter $alsobad
