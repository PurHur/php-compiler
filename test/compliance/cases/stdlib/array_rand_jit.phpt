--TEST--
stdlib array_rand() JIT on packed lists (#2321)
--FILE--
<?php
$a = array();
$a[] = 'p';
$a[] = 'q';
$a[] = 'r';
$k = array_rand($a);
echo (is_int($k) && isset($a[$k])) ? 'one' : 'bad', "\n";
--EXPECT--
one
