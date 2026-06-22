<?php

declare(strict_types=1);

/**
 * Maintainer repro: nested scalar-return builtin in call argument (#10495).
 */

echo var_export(get_debug_type(null), true), "\n";
echo var_export(gettype(null), true), "\n";
echo var_export(json_encode(null), true), "\n";
echo var_export(get_class(new stdClass()), true), "\n";

$x = get_debug_type(null);
echo var_export($x, true), "\n";
