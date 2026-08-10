--TEST--
AOT UnhandledMatchError get_class + message match Zend (#29747, re-#27625)
--FILE--
<?php
try {
    match (3) { 1 => 'a' };
} catch (Throwable $e) {
    echo 'class=[', get_class($e), '] msg=[', $e->getMessage(), "]\n";
    echo 'ume=', $e instanceof UnhandledMatchError ? '1' : '0', "\n";
}
try {
    $a = 1;
    $b = 0;
    $a % $b;
} catch (Throwable $e) {
    echo 'class=[', get_class($e), '] msg=[', $e->getMessage(), "]\n";
    echo 'dze=', $e instanceof DivisionByZeroError ? '1' : '0', "\n";
}
--EXPECT--
class=[UnhandledMatchError] msg=[Unhandled match case 3]
ume=1
class=[DivisionByZeroError] msg=[Modulo by zero]
dze=1
