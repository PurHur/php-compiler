--TEST--
stdlib array_walk() string callback receives value and key on array and object (#9291, php-src-strict)
--FILE--
<?php
ob_start();
$a = ['x' => 1];
array_walk($a, 'var_dump');
$arrayOut = ob_get_clean();

ob_start();
$o = (object) ['x' => 1];
array_walk($o, 'var_dump');
$objectOut = ob_get_clean();

echo $arrayOut === $objectOut ? 'match' : 'mismatch', "\n";
echo trim($arrayOut), "\n";
--EXPECT--
match
int(1)
string(1) "x"
