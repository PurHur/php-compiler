<?php
/**
 * AOT probe #23596 — named mt_srand/srand seed:/mode: must compile and match positional.
 * ReflectionFunction may be unavailable under thin AOT.
 */
mt_srand(1, MT_RAND_PHP);
$posMt = mt_rand();
mt_srand(seed: 1, mode: MT_RAND_PHP);
$namedMt = mt_rand();
echo $posMt === $namedMt ? "mt_ok\n" : "mt_bad\n";

srand(1, MT_RAND_PHP);
$posS = rand();
srand(seed: 1, mode: MT_RAND_PHP);
$namedS = rand();
echo $posS === $namedS ? "srand_ok\n" : "srand_bad\n";
