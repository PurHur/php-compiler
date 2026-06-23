<?php

declare(strict_types=1);

// Issue #10859 — hrtime()[1] must have full nanosecond precision (php-src ext/standard/hrtime.c).

$mods = [];
for ($i = 0; $i < 10; ++$i) {
    $mods[] = hrtime()[1] % 1000;
}
$anyNonZero = false;
foreach ($mods as $m) {
    if (0 !== $m) {
        $anyNonZero = true;

        break;
    }
}
echo $anyNonZero ? "mod1000_ok\n" : "mod1000_fail\n";

$a = hrtime()[1];
$b = hrtime()[1];
echo $a !== $b ? "vary_ok\n" : "vary_fail\n";
