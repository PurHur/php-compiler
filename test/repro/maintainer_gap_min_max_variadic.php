<?php

declare(strict_types=1);

/**
 * Maintainer repro: min()/max() variadic scalar operands (#11668, ext/standard/array.c).
 */

echo 'min_ab=', min('a', 'b'), ' max_ab=', max('a', 'b'), "\n";
echo 'min_arr=', min(['a', 'b']), ' min_nums=', min(1, 2, 3), "\n";
