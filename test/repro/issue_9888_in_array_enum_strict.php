<?php

declare(strict_types=1);

enum E: int
{
    case A = 1;
    case B = 2;
}

$a = [E::A, E::B];

echo "var-haystack-loose: ", var_export(in_array(E::A, $a), true), "\n";
echo "var-haystack-strict: ", var_export(in_array(E::A, $a, true), true), "\n";
echo "inline-haystack-loose: ", var_export(in_array(E::A, [E::A, E::B]), true), "\n";
echo "inline-haystack-strict: ", var_export(in_array(E::A, [E::A, E::B], true), true), "\n";
echo "array-search-strict: ", var_export(array_search(E::A, [E::A, E::B], true), true), "\n";
