--TEST--
stdlib array_walk() — named variable (object) cast is by-ref lvalue JIT (#17989, ext/standard/array.c)
--JIT--
--FILE--
<?php
declare(strict_types=1);

$a = (object) ['x' => 1];
ob_start();
array_walk($a, static fn ($v) => print($v));
echo ob_get_clean(), "\n";
?>
--EXPECT--
1
