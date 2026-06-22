<?php
function add(int $a, int $b): int { return $a + $b; }

echo 'user_inline=', (add(...))(2, 3), "\n";

$c = strlen(...);
echo 'builtin_var=', $c('hi'), "\n";

echo 'builtin_inline=', (strlen(...))('hi'), "\n";
