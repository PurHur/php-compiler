<?php
/**
 * Repro #23596 — mt_srand()/srand() Zend stub names seed/mode; named args + srand two-arg.
 * php-src: ext/random/random.stub.php
 */
foreach (['mt_srand', 'srand'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $n = [];
    $defs = [];
    foreach ($rf->getParameters() as $p) {
        $n[] = $p->getName();
        $defs[] = $p->isOptional()
            ? ($p->isDefaultValueAvailable() ? (string) $p->getDefaultValue() : '?')
            : 'req';
    }
    echo $fn, ' arity=', $rf->getNumberOfParameters(),
        ' req=', $rf->getNumberOfRequiredParameters(),
        ' names=', implode(',', $n),
        ' def=', implode(',', $defs),
        ' ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'none',
        PHP_EOL;
}

mt_srand(1, MT_RAND_PHP);
$posMt = mt_rand();
mt_srand(seed: 1, mode: MT_RAND_PHP);
$namedMt = mt_rand();
echo 'mt_named=', $posMt === $namedMt ? 'match' : 'mismatch', PHP_EOL;

mt_srand(1, MT_RAND_MT19937);
$mt19937 = mt_rand();
echo 'mt_modes=', $posMt === $mt19937 ? 'same' : 'differ', PHP_EOL;

srand(1, MT_RAND_PHP);
$posS = rand();
srand(seed: 1, mode: MT_RAND_PHP);
$namedS = rand();
echo 'srand_named=', $posS === $namedS ? 'match' : 'mismatch', PHP_EOL;

try {
    srand(1, MT_RAND_MT19937, 3);
    echo "srand3=ok\n";
} catch (Throwable $e) {
    echo 'srand3=', get_class($e), PHP_EOL;
}
