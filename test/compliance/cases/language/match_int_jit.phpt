--TEST--
JIT: match on int literals with default arm (issue #143, #2428)
--FILE--
<?php
echo match (2) {
    1 => 'a',
    2 => 'b',
    default => 'c',
}, "\n";
echo match ('GET') {
    'GET' => 'get',
    'POST' => 'post',
    default => 'other',
}, "\n";
--EXPECT--
b
get
