--TEST--
match on bool and string literals (issue #2398, #2428; NameResolver true/false)
--FILE--
<?php
echo match (true) {
    false => 'f',
    true => 't',
    default => 'd',
}, "\n";
echo match ('ok') {
    'no' => 'n',
    'ok' => 'y',
    default => '?',
}, "\n";
--EXPECT--
t
y
