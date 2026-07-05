<?php

declare(strict_types=1);

/**
 * array_search() strict — int needle must match int haystack before enum case (#16316, re-#8886).
 *
 * Zend: php test/repro/maintainer_gap_array_search_strict_scalar_order.php
 * VM:   php bin/vm.php test/repro/maintainer_gap_array_search_strict_scalar_order.php
 */

enum E: int
{
    case A = 1;
}

var_export(array_search(1, [1, E::A], true));
echo "\n";
