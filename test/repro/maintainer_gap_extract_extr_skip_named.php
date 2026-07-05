<?php

declare(strict_types=1);

$a = 1;
$n = extract(['a' => 99, 'b' => 2], flags: EXTR_SKIP);
echo "n={$n}\n";
echo "a={$a}\n";
echo isset($b) ? "b={$b}\n" : "b=unset\n";
