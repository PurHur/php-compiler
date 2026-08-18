--TEST--
stdlib mt_srand()/srand() named seed/mode + srand two-arg (#23596, ext/random/random.stub.php)
--FILE--
<?php
foreach (['mt_srand', 'srand'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $n = [];
    foreach ($rf->getParameters() as $p) {
        $n[] = $p->getName();
    }
    echo $fn, ' names=', implode(',', $n), ' req=', $rf->getNumberOfRequiredParameters(), "\n";
}
mt_srand(1, MT_RAND_PHP);
$pos = mt_rand();
mt_srand(seed: 1, mode: MT_RAND_PHP);
$named = mt_rand();
echo $pos === $named ? "mt_named=match\n" : "mt_named=mismatch\n";
mt_srand(1, MT_RAND_MT19937);
echo mt_rand() === $pos ? "mt_modes=same\n" : "mt_modes=differ\n";
srand(1, MT_RAND_PHP);
$sPos = rand();
srand(seed: 1, mode: MT_RAND_PHP);
echo $sPos === rand() ? "srand_named=match\n" : "srand_named=mismatch\n";
try {
    srand(1, 0, 3);
    echo "srand3=ok\n";
} catch (ArgumentCountError $e) {
    echo "srand3=ace\n";
}
--EXPECT--
mt_srand names=seed,mode req=0
srand names=seed,mode req=0
mt_named=match
mt_modes=differ
srand_named=match
srand3=ace
