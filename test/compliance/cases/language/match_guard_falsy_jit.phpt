--TEST--
JIT: match falsy subject — strict === for bool patterns (#4516; VM fallback until MCJIT merge fixed)
--FILE--
<?php
echo match (0) {
    1 => 'one',
    true => 'true-arm',
    default => 'def',
}, "\n";
--EXPECT--
def
