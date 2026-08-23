<?php

declare(strict_types=1);

/**
 * #33977 — AOT array_map() string builtins outside the typed allowlist.
 * php-src: ext/standard/array.c — php_array_map()
 */

echo 'abs=', implode(',', array_map('abs', [-1, 2, -3])), "\n";
echo 'upper=', implode(',', array_map('strtoupper', ['a', 'b'])), "\n";

$t = tempnam(sys_get_temp_dir(), 'a33977');
file_put_contents($t, 'x');
array_map('unlink', [$t]);
echo 'unlink=', file_exists($t) ? 'yes' : 'no', "\n";
