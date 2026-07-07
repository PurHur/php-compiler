<?php

declare(strict_types=1);

// Maintainer repro: var_export($expr !== false, true) must export comparison bool (#17250, re-#13694).
echo var_export(1 !== false, true), "\n";
echo var_export(1 != false, true), "\n";
echo var_export([1] !== false, true), "\n";
$a = ['x' => 1];
echo var_export(array_search('x', $a, true) !== false, true), "\n";
echo var_export(0 === false, true), "\n";
echo "ok\n";
