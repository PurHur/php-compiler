<?php

$a = [1, 2, 3, 4];
echo implode(',', array_filter($a, static fn(int $x): bool => $x % 2 === 0)), "\n";
