--TEST--
AOT: empty() for scalars
--FILE--
<?php
echo empty(0) ? 'y' : 'n', "\n";
echo empty(1) ? 'y' : 'n', "\n";
--EXPECT--
y
n
