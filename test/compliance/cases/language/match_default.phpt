--TEST--
match default arm when no literal arm matches (issue #2398, #2428)
--FILE--
<?php
echo match (99) {
    1 => 'one',
    2 => 'two',
    default => 'other',
}, "\n";
echo match ('POST') {
    'GET' => 'get',
    default => 'fallback',
}, "\n";
--EXPECT--
other
fallback
