<?php

/**
 * Repro #31097 — Xoshiro256StarStar::jump / PcgOneseq128XslRr64::jump excess argc.
 * php-src: ext/random/engine_xoshiro256starstar.c / engine_pcgoneseq128xslrr64.c
 */
function show(string $label, callable $fn): void
{
    try {
        $fn();
        echo $label, ": OK\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

$x = new Random\Engine\Xoshiro256StarStar(42);
show('Xoshiro jump()', static fn () => $x->jump());
show('Xoshiro jump(1)', static fn () => $x->jump(1));
show('Xoshiro jumpLong()', static fn () => $x->jumpLong());
show('Xoshiro jumpLong(1)', static fn () => $x->jumpLong(1));

$p = new Random\Engine\PcgOneseq128XslRr64(42);
show('PCG jump()', static fn () => $p->jump());
show('PCG jump(1)', static fn () => $p->jump(1));
show('PCG jump(1,2)', static fn () => $p->jump(1, 2));
