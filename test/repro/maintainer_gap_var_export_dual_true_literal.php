<?php
declare(strict_types=1);

echo var_export(in_array('a', ['a'], true), true), "\n";
echo var_export(array_search('b', ['a', 'b'], true), true), "\n";

$haystack = ['tlsv1.2', 'tlsv1.3'];
echo var_export(in_array('tlsv1.2', $haystack, true), true), "\n";
