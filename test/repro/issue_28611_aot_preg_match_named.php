<?php

declare(strict_types=1);

preg_match('/(?<n>\d+)/', 'a12', $m);
echo ($m['n'] ?? 'MISSING'), "\n";
