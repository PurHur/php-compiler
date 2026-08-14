<?php

/**
 * Repro #31092 — Random\Randomizer getBytes/getInt/shuffleArray/pickArrayKeys excess argc.
 * php-src: ext/random/randomizer.c ZEND_PARSE_PARAMETERS_*
 */
function show(string $label, callable $fn): void
{
    try {
        $v = $fn();
        echo $label, ': OK ', var_export($v, true), "\n";
    } catch (Throwable $e) {
        echo $label, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}

$r = new Random\Randomizer(new Random\Engine\Mt19937(1));
show('getBytes+1', static fn () => $r->getBytes(1, 1));
show('getInt+1', static fn () => $r->getInt(1, 2, 1));
show('shuffleArray+1', static fn () => $r->shuffleArray([1, 2], 1));
show('pickArrayKeys+1', static fn () => $r->pickArrayKeys([1 => 'a', 2 => 'b'], 1, 1));
show('getBytes_ok', static fn () => strlen($r->getBytes(1)));
show('getInt_ok', static fn () => $r->getInt(1, 1));
show('shuffleArray_ok', static fn () => count($r->shuffleArray([1, 2])));
show('pickArrayKeys_ok', static fn () => count($r->pickArrayKeys([1 => 'a', 2 => 'b'], 1)));
