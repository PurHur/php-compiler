--TEST--
stdlib array_walk() — named (object) cast variable after ob_start() (#17989, ext/standard/array.c)
--FILE--
<?php
$a = (object) ['x' => 1];
ob_start();
array_walk($a, static fn ($v) => print($v));
echo ob_get_clean();
?>
--EXPECT--
1
