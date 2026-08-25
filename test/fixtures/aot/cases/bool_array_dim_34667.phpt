--TEST--
AOT: bool array dimension coerces true→1 for numeric string keys (#34667)
--FILE--
<?php
declare(strict_types=1);
$a = ['1' => 7];
echo $a[true], "\n";
$a2 = ['1' => 1];
$k = true;
var_dump(isset($a2[$k]));
?>
--EXPECT--
7
bool(true)
