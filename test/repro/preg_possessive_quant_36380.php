<?php
// Repro: Parsedown table-cell preg uses possessive ++ (#36380).
$pat = '/(?:(\\\\[|])|[^|`]|`[^`]++`|`)++/';
$row = 'a|b|c';
$matches = null;
$n = preg_match_all($pat, $row, $matches);
echo "n=$n\n";
echo 'cells=', isset($matches[0]) ? implode(',', $matches[0]) : 'MISSING', "\n";
echo 'slice=', implode(',', array_slice($matches[0] ?? [], 0, 2)), "\n";
echo 'poss=', (int) preg_match('/a++/', 'aaa'), ',', (int) preg_match('/a++a/', 'aaa'), "\n";
