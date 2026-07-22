<?php
/** Repro #22242 — ReflectionGenerator::isClosed() PHP 8.4 php-src-strict. */
declare(strict_types=1);

function gen() {
    yield 1;
}

function gen_return() {
    yield 1;
    return 42;
}

$g = gen();
$r = new ReflectionGenerator($g);
if (!method_exists($r, 'isClosed')) {
    fwrite(STDERR, "MISSING ReflectionGenerator::isClosed\n");
    exit(1);
}
echo 'fresh=', $r->isClosed() ? 'T' : 'F', "\n";
$g->current();
echo 'yielded=', $r->isClosed() ? 'T' : 'F', "\n";
$g->next();
echo 'exhausted=', $r->isClosed() ? 'T' : 'F', "\n";

$g2 = gen_return();
$r2 = new ReflectionGenerator($g2);
$g2->current();
$g2->next();
echo 'returned=', $r2->isClosed() ? 'T' : 'F', " getReturn=", var_export($g2->getReturn(), true), "\n";
