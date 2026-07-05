--TEST--
stdlib flock() null $operation — ValueError JIT (#16575, ext/standard/flock.c)
--FILE--
<?php
$fp = fopen('php://memory', 'r+');
try {
    flock($fp, null);
    echo "uncaught\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
flock(): Argument #2 ($operation) must be one of LOCK_SH, LOCK_EX, or LOCK_UN
