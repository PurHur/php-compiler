--TEST--
stdlib str_ends_with() — binary-safe NUL bytes JIT execute (#4390)
--FILE--
<?php
$hay = 'a'.chr(0).'b';
echo str_ends_with($hay, chr(0).'b') ? '1' : '0', "\n";
echo str_ends_with($hay, 'b') ? '1' : '0', "\n";
echo str_ends_with($hay, 'a') ? '1' : '0', "\n";
echo str_ends_with($hay, '') ? '1' : '0', "\n";
?>
--EXPECT--
1
1
0
1
