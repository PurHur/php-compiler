<?php
/**
 * Issue #33863 — AOT (array) cast must match Zend convert_to_array.
 * Avoid var_export(array): thin standalone AOT aborts without Runtime->vm (#26855).
 */
echo implode(',', (array) [1, 2]), "\n";
echo implode(',', (array) null), "\n";
echo implode(',', (array) 7), "\n";
$ao = new ArrayObject(['a' => 1, 'b' => 2]);
echo implode(',', $ao->getArrayCopy()), "\n";
echo implode(',', (array) $ao), "\n";
?>
