<?php
/** Repro #34690: bool keys in array literals must convert_to_long (zext), not trunc i1→i64. */
$a = [true => 7];
echo $a[true], "\n";
$b = [true => [true => 9]];
echo $b[true][true], "\n";
var_dump(isset($b[1][1]));
