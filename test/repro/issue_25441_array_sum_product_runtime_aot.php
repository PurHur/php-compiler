<?php
/**
 * Issue #25441 AOT smoke — runtime results (Reflection is VM/JIT).
 * array_product AOT currently mis-returns Object (pre-existing; not this change).
 */
echo array_sum([1, 2, 3]), "\n";
echo gettype(array_sum([1.5, 2.5])), "\n";
echo array_sum(array: [10, 20]), "\n";
