--TEST--
match default arm when no literal arm matches (issue #2398, #2428)
--FILE--
<?php
echo match (99) {
    1 => 'a',
    2 => 'b',
    default => 'fallback',
}, "\n";
echo match ('MISS') {
    'GET' => 'get',
    'POST' => 'post',
    default => 'other',
}, "\n";
--EXPECT--
fallback
other
