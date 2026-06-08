--TEST--
JIT: pack() — invalid format throws ValueError (#4532)
--JIT--
--FILE--
<?php
try {
    pack('!', 1);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}

try {
    pack('cc', 1);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Type !: unknown format code
Type c: too few arguments
