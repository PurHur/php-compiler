<?php

declare(strict_types=1);

/**
 * Repro #8796: in_array()/array_search() enum needle must match enum haystack (php-src array.c).
 *
 * VM: php bin/vm.php test/repro/maintainer_in_array_search_enum.php
 * Zend: php test/repro/maintainer_in_array_search_enum.php
 */

enum E: int
{
    case A = 1;
    case B = 2;
}

var_export(in_array(E::A, [E::A, E::B]));
echo "\n";
var_export(in_array(E::A, [E::A, E::B], true));
echo "\n";
var_export(array_search(E::A, [E::A, E::B]));
echo "\n";
var_export(in_array(1, [E::A], false));
echo "\n";
