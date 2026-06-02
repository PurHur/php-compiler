--TEST--
match guard / value arms — falsy subject uses === not truthiness (#4516; zend_compile.c)
--FILE--
<?php
echo match (0) {
    1 => 'one',
    true => 'true-arm',
    default => 'def',
}, "\n";

echo match (0) {
    0 => 'zero',
    default => 'def',
}, "\n";
--EXPECT--
def
zero
