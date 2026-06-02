--TEST--
language: compact() JIT with float/double locals (#4094)
--FILE--
<?php
$x = 1.5;
echo json_encode(compact('x'));
--EXPECT--
{"x":1.5}
