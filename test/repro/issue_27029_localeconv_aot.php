<?php

declare(strict_types=1);

// Repro #27029 — AOT localeconv() must emit grouping arrays (not getStringType fatal).
$lc = localeconv();
echo $lc['decimal_point'], PHP_EOL;
echo is_array($lc['grouping']) ? '1' : '0', PHP_EOL;
echo is_array($lc['mon_grouping']) ? '1' : '0', PHP_EOL;
echo $lc['int_frac_digits'], PHP_EOL;
