<?php
/**
 * Repro for #21754: random_int(null, $max) should coerce null→0, not LogicException.
 *
 * Zend: int(0) (with E_DEPRECATED on 8.4 forward profile).
 */

$result = random_int(null, 1);
echo 'type:', gettype($result), "\n";
echo 'gte0:', ($result >= 0 ? 'yes' : 'no'), "\n";
echo 'lte1:', ($result <= 1 ? 'yes' : 'no'), "\n";

$result2 = random_int(null, null);
echo 'null_both:', $result2, "\n";
