<?php
$a = [1, 2, 3, 4, 5];
$s1 = array_slice($a, 1, 2);
$s2 = array_slice($a, -2);
echo $s1[0], '|', $s1[1], "\n";
echo $s2[0], '|', $s2[1];
