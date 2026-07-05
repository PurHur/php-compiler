<?php

declare(strict_types=1);

if (!function_exists('array_first')) {
    echo "skip\n";
    exit(0);
}

$first = array_first([]);
$last = array_last([]);
echo null === $first && null === $last ? "ok:null\n" : 'fail:first='.var_export($first, true).' last='.var_export($last, true)."\n";
exit(null === $first && null === $last ? 0 : 1);
