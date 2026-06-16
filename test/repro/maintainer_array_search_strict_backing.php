<?php

declare(strict_types=1);

/**
 * array_search() strict — scalar needle must not match enum case via backing (#8886).
 *
 * Zend: php test/repro/maintainer_array_search_strict_backing.php
 * VM:   php bin/vm.php test/repro/maintainer_array_search_strict_backing.php
 */

enum E: int
{
    case A = 1;
}

var_export(array_search(1, [E::A, 1], true));
echo "\n";
var_export(array_search(1, [1, E::A], true));
echo "\n";
