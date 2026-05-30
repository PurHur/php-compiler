--TEST--
match comma-separated conditions in one arm (issue #3717; Zend zend_compile.c)
--FILE--
<?php
echo match (true) {
    true, false => 'yes',
}, "\n";
echo match (3) {
    1, 2, 3 => 'small',
    default => 'other',
}, "\n";
echo match ('x') {
    'a', 'b' => 'ab',
    default => '?',
}, "\n";
--EXPECT--
yes
small
?
