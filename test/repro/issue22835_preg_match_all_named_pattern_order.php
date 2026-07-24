<?php
declare(strict_types=1);

preg_match_all('/(?<n>\d+)/', 'a12b34', $m);
var_export(isset($m['n']));
echo "\n";
var_export($m['n'] ?? null);
echo "\n";
