<?php

declare(strict_types=1);

$t = 1710000000;
echo date('B', $t), "\n";
echo (string) idate('B', $t), "\n";
echo gmdate('B', $t), "\n";
