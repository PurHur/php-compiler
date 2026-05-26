--TEST--
JIT: match uses strict === comparison (issue #143, #2428)
--FILE--
<?php
echo match ('2') {
    2 => 'int',
    '2' => 'str',
    default => 'other',
}, "\n";
--EXPECT--
str
