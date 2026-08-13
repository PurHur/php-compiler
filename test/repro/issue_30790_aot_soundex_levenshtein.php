<?php
/**
 * Repro #30790 — AOT soundex()/levenshtein() must not segfault (NestedJIT strlen/substr).
 * @differential-repeat: 5
 */
echo soundex('Euler'), "\n";
echo soundex(''), "\n";
echo levenshtein('kitten', 'sitting'), "\n";
echo levenshtein('', 'abc'), "\n";
echo levenshtein('abc', 'ab', 2, 1, 1), "\n";
