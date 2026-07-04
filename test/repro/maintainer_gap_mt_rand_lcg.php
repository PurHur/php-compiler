<?php

declare(strict_types=1);

mt_srand(12345);
$a = mt_rand(1, 100);
$b = mt_rand(1, 100);
echo "$a $b ", getrandmax(), "\n";
$lcg = lcg_value();
echo ($lcg >= 0.0 && $lcg <= 1.0) ? "lcg_range=ok\n" : "lcg_range=bad\n";
