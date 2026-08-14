<?php

/**
 * Repro #31034 — Generator::rewind() excess argc → ArgumentCountError.
 * php-src: Zend/zend_generators.c — zend_generator_rewind
 */
function gen()
{
    yield 1;
}

$g = gen();
try {
    $g->rewind(1);
    echo "rewind:SILENT\n";
} catch (Throwable $e) {
    echo 'rewind:', get_class($e), ':', $e->getMessage(), "\n";
}

$h = (function () {
    yield 7;
})();
$h->rewind();
echo 'ok=', 7 === $h->current() ? '1' : '0', "\n";
