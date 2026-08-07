<?php

declare(strict_types=1);

/**
 * Repro #28387 — Random\Randomizer + Engine\* must be final
 * (php-src ext/random/random.stub.php).
 *
 * Run: PHP_COMPILER_PROFILE=8.4 php bin/vm.php test/repro/issue_28387_random_final.php
 */
$classes = [
    'Random\\Randomizer',
    'Random\\Engine\\Mt19937',
    'Random\\Engine\\Secure',
    'Random\\Engine\\PcgOneseq128XslRr64',
    'Random\\Engine\\Xoshiro256StarStar',
];
foreach ($classes as $c) {
    echo $c, ' isFinal=', var_export((new ReflectionClass($c))->isFinal(), true), "\n";
}
eval('class BadRandomizer extends Random\\Randomizer {}');
echo "EXTENDED_OK\n";
