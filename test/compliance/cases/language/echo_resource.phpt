--TEST--
language: VM echo resource handle (issue #4740)
--FILE--
<?php
$f = fopen('php://memory', 'r');
echo $f, "\n";
--EXPECT--
Resource id #1
