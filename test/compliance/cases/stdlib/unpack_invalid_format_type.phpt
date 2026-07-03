--TEST--
Stdlib: unpack() unknown format type throws ValueError (#15502, ext/standard/pack.c)
--FILE--
<?php
try {
    unpack('o', 'abcd');
    echo "no_exception\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
Invalid format type o
