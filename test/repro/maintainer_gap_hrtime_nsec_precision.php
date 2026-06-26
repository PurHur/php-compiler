<?php

declare(strict_types=1);

$arr = hrtime();
echo ($arr[1] % 1000), "\n";
echo (hrtime(true) % 1000), "\n";
