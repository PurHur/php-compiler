--TEST--
AOT: match falsy subject — strict === for bool/null patterns (#4516)
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
