--TEST--
JIT: stream resource === and == compare handle identity (#4699)
--FILE--
<?php
$a = fopen('php://memory', 'r+');
$b = fopen('php://memory', 'r+');
echo ($a === $b) ? 'same' : 'diff', "\n";
echo ($a == $b) ? 'same' : 'diff', "\n";
$id = (int) $a;
echo ($a === $id) ? 'same' : 'diff', "\n";
echo ($a == $id) ? 'same' : 'diff', "\n";
fclose($a);
fclose($b);
?>
--EXPECT--
diff
diff
diff
diff
