--TEST--
stdlib flock() null $operation — TypeError not ValueError (#16595, ext/standard/flock.c)
--FILE--
<?php
$fp = fopen('php://memory', 'r+');
try {
    flock($fp, null);
    echo "no_throw\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
} finally {
    fclose($fp);
}
--EXPECT--
flock(): Argument #2 ($operation) must be of type int, null given
