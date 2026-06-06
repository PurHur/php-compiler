--TEST--
Language: throw statement with ternary operand — Error arm is Throwable (#7037)
--FILE--
<?php
function f(bool $b): void
{
    throw $b ? new Exception('a') : new Error('b');
}

try {
    f(false);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}

try {
    f(true);
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
b
a
