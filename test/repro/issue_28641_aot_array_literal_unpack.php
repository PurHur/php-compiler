<?php

declare(strict_types=1);

// #28641 — AOT array-literal unpack [...$a] must match Zend/VM (not abort empty).
$a = [1, 2];
$b = [0, ...$a, 3];
echo implode(',', $b), "\n";