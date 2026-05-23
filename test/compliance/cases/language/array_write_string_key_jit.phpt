--TEST--
language: array string-key write and read JIT (issue #107)
--FILE--
<?php
$a = [];
$a['hello'] = 'world';
echo $a['hello'], "\n";
--EXPECT--
world
