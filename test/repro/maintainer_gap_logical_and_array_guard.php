<?php

// Maintainer gap / issue #10626 — && with [] !== $array guard must yield bool, not haystack string.
$warnings = ['filetype(): Lstat failed for /no/such/phpc-logical-and-guard'];
var_export([] !== $warnings && str_contains($warnings[0], 'Lstat failed'));
echo "\n";
$r1 = [] !== $warnings;
$r2 = str_contains($warnings[0], 'Lstat failed');
var_export($r1 && $r2);
echo "\n";
