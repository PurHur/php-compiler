--TEST--
stdlib flock() null $operation — TypeError JIT (#16595, ext/standard/flock.c)
--FILE--
<?php
$fp = fopen('php://memory', 'r+');
try {
    flock($fp, null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
flock(): Argument #2 ($operation) must be of type int, null given
