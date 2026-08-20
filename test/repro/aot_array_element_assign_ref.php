<?php

declare(strict_types=1);

/**
 * AOT: $r =& $a[0] must bind to the packed slot (Zend ZEND_MAKE_REF on FETCH_DIM_W).
 * Broken AOT treated the dim-fetch temp as non-referenceable and copied by value.
 */
$a = [1, 2];
$r = &$a[0];
$r = 9;
echo $a[0], "\n";
