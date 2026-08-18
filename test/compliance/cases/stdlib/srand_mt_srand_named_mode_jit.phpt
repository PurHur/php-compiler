--TEST--
stdlib mt_srand()/srand() named seed/mode JIT (#23596, ext/random/random.stub.php)
--FILE--
<?php
mt_srand(1, MT_RAND_PHP);
$pos = mt_rand();
mt_srand(seed: 1, mode: MT_RAND_PHP);
$named = mt_rand();
echo $pos === $named ? "mt_named=match\n" : "mt_named=mismatch\n";
srand(1, MT_RAND_PHP);
$sPos = rand();
srand(seed: 1, mode: MT_RAND_PHP);
echo $sPos === rand() ? "srand_named=match\n" : "srand_named=mismatch\n";
--EXPECT--
mt_named=match
srand_named=match
