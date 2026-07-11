<?php

declare(strict_types=1);

$r = new Random\Randomizer(new Random\Engine\Mt19937(99));
$n = $r->nextInt();
if (0 === $n) {
    fwrite(STDERR, "fail: nextInt()=0\n");
    exit(1);
}
echo "ok: {$n}\n";
