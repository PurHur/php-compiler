<?php

declare(strict_types=1);

$arrow = preg_replace_callback('/a/', fn (array $m): string => strtoupper($m[0]), ['abc', 'x']);
$closure = preg_replace_callback('/a/', function (array $m): string {
    return strtoupper($m[0]);
}, ['abc', 'x']);
echo 'arrow=', var_export($arrow, true), "\n";
echo 'closure=', var_export($closure, true), "\n";
if ($arrow !== ['Abc', 'x'] || $closure !== ['Abc', 'x']) {
    exit(1);
}
