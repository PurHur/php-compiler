<?php

declare(strict_types=1);

// Issue #15783 — var_export() on array literal with inline call elements.

var_export([0, strlen('x')]);
echo "\n";

var_export([true, strlen('ab')]);
echo "\n";

$dt = new DateTime('2020-01-01');
var_export([$dt instanceof DateTime, $dt->format('Y-m-d')]);
echo "\n";
