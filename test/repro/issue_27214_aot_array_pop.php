<?php

declare(strict_types=1);

/**
 * #27214 — AOT array_pop must match Zend/VM/JIT (packed list).
 *
 * Expected stdout: 3,1,2
 */
$a = [1, 2, 3];
echo array_pop($a), ',', implode(',', $a), "\n";
