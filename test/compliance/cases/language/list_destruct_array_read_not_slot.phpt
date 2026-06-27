--TEST--
List destruct writable guard: array read temps are not destructuring slots (#12602)
--FILE--
<?php
declare(strict_types=1);
$options = ['key' => 'val'];
$v = $options['key'];
echo $v, "\n";
?>
--EXPECT--
val
