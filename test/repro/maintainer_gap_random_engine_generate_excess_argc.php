<?php

/**
 * Repro #31096 — Random\Engine\*::generate() excess argc.
 * php-src: ext/random/engine_*.c ZEND_PARSE_PARAMETERS exactly 0
 */
function show(string $label, callable $fn): void
{
    try {
        $v = $fn();
        echo $label, ': OK len=', strlen((string) $v), "\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

$engines = [
    'Secure' => new Random\Engine\Secure(),
    'Mt19937' => new Random\Engine\Mt19937(1),
    'Xoshiro256StarStar' => new Random\Engine\Xoshiro256StarStar(42),
    'PcgOneseq128XslRr64' => new Random\Engine\PcgOneseq128XslRr64(42),
];
foreach ($engines as $name => $e) {
    show($name.'+1', static fn () => $e->generate(8));
    show($name.'_ok', static fn () => $e->generate());
}
