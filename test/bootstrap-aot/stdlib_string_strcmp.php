<?php
declare(strict_types=1);
$a = 'lib/' . 'JIT.php';
$b = 'lib/JIT.php';
echo strcmp($a, $b) === 0 ? '1' : '0';
echo "\n";
