<?php
/**
 * Parity: count() with inline array literal + COUNT_RECURSIVE.
 * Zend: returns recursive element count. VM: TypeError (null operand).
 */
declare(strict_types=1);

$n = count([1, [2, 3]], COUNT_RECURSIVE);
echo "count=" . $n . "\n";
