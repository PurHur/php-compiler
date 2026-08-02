<?php
/**
 * Repro #26830 — AOT levenshtein must match Zend/VM (not silent 0).
 * @differential-repeat: 5
 */
$a = 'kitten';
$b = 'sitting';
echo levenshtein($a, $b), "\n";
echo levenshtein('', 'abc'), "\n";
echo levenshtein('abc', 'ab', 2, 1, 1), "\n";
