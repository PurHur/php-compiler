<?php

declare(strict_types=1);

$methods = get_class_methods(Random\Randomizer::class);
echo 'method_count='.\count($methods)."\n";

$engine = new Random\Engine\Mt19937(1234);
$randomizer = new Random\Randomizer($engine);
$first = $randomizer->getInt(1, 100);
echo 'getInt='.$first."\n";

if (76 !== $first) {
    echo "fail: expected getInt=76, got {$first}\n";
    exit(1);
}

echo "ok\n";
