<?php

declare(strict_types=1);

$a = array_pad(['a' => 1, 'b' => 2, 'c' => 3], -4, 0);
echo $a[0], ':', $a['a'], ':', $a['c'], "\n";
$b = array_pad(['a' => 1, 'b' => 2, 'c' => 3], -5, 0);
echo $b[0], ':', $b[1], ':', $b['b'], "\n";
