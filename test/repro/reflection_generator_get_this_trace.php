<?php
/** Repro #22067 — ReflectionGenerator::getThis()/getTrace() php-src-strict. */
declare(strict_types=1);

class G {
    public function gen(): Generator {
        yield $this;
        yield 2;
    }
}

$g = (new G())->gen();
$g->current();
$ref = new ReflectionGenerator($g);
foreach (['getThis', 'getTrace'] as $m) {
    if (!method_exists($ref, $m)) {
        fwrite(STDERR, "MISSING ReflectionGenerator::$m\n");
        exit(1);
    }
}
$t = $ref->getThis();
echo is_object($t) ? get_class($t) : var_export($t, true), "\n";
$tr = $ref->getTrace();
echo count($tr), "\n";
echo $tr[0]['function'] ?? '?', "\n";
