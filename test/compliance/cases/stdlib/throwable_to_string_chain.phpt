--TEST--
Stdlib: Throwable __toString() with previous chain (VM, #7159)
--FILE--
<?php
$inner = new Exception('inner');
$outer = new Exception('outer', 0, $inner);
echo (string) $outer, "\n";
echo 'bare ', substr((string) new Exception('msg'), 0, 9), "\n";
--EXPECTF--
Exception: inner in %s:%d
Stack trace:
#0 {main}

Next Exception: outer in %s:%d
Stack trace:
#0 {main}
bare Exception
