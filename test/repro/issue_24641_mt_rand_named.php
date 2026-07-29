<?php
/** Repro for #24641 — mt_rand Reflection req=0 + named min/max. */
$r = new ReflectionFunction('mt_rand');
$bits = [];
foreach ($r->getParameters() as $p) {
    $bits[] = $p->getName() . ($p->isOptional() ? '=' : '');
}
echo 'req=', $r->getNumberOfRequiredParameters(), ' [', implode(',', $bits), "]\n";

$n = mt_rand(min: 1, max: 2);
echo ($n === 1 || $n === 2) ? "named=ok\n" : "named=bad\n";

try {
    mt_rand(1);
    echo "1arg=accepted\n";
} catch (ArgumentCountError $e) {
    echo str_contains($e->getMessage(), 'exactly 2') ? "1arg=exactly2\n" : "1arg=other\n";
}

echo 'zero=', is_int(mt_rand()) ? 'int' : 'other', "\n";
