<?php

declare(strict_types=1);

preg_match('/(?<n>a)/', 'a', $m);
var_export(array_key_exists('n', $m));
echo "\n";
preg_match('/(?<n>a)/', 'a', $m, PREG_UNMATCHED_AS_NULL);
var_export($m);
