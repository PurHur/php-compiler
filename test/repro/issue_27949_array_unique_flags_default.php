<?php

declare(strict_types=1);

$r = new ReflectionFunction('array_unique');
$flags = $r->getParameters()[1];
echo 'default:', var_export($flags->getDefaultValue(), true), "\n";
echo 'SORT_STRING:', SORT_STRING, "\n";
// Runtime: omitted flags use SORT_STRING (string-cast dedup)
$u = array_unique(['1', 1, 2]);
echo 'runtime:', count($u), "\n";
