<?php

declare(strict_types=1);

/** Issue #3470 — float lookup keys coerce like php-src ext/standard/array.c. */
$a = [1.5 => 'v'];
var_export(array_key_exists(1.5, $a));
echo "\n";
var_export(array_key_exists(1, $a));
echo "\n";
