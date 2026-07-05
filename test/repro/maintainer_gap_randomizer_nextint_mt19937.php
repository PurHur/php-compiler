<?php

declare(strict_types=1);

$engine = new Random\Engine\Mt19937(99);
$randomizer = new Random\Randomizer($engine);
$value = $randomizer->nextInt();
if (0 === $value) {
    echo "fail: nextInt()=0\n";
    exit(1);
}
echo 'ok: '.$value."\n";
