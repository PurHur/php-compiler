--TEST--
AOT: match default arm (issue #2398, #2428)
--FILE--
<?php
echo match (99) {
    1 => 'one',
    2 => 'two',
    default => 'other',
}, "\n";
--EXPECT--
other
