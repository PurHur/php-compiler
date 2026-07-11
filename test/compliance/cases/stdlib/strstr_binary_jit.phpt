--TEST--
stdlib strstr() — binary-safe NUL bytes JIT compile (#14017)
--FILE--
<?php
$s = 'aa'.chr(0).'bb';
echo strstr($s, 'bb'), "\n";
?>
--EXPECT--
bb
