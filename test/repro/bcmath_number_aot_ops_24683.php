<?php
// AOT BcMath\Number +−*/ under PROFILE=8.4 (#24683)
// Expect: 12 / 8 / 20 / 5
$a = new BcMath\Number("10");
$b = new BcMath\Number("2");
echo $a + $b, "\n";
echo ($a - $b), "\n";
echo ($a * $b), "\n";
echo ($a / $b), "\n";
