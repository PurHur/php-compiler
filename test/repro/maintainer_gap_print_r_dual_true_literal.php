<?php
declare(strict_types=1);

echo 'in_array: ', print_r(in_array('x', ['x'], true), true), "\n";
echo 'array_search: ', print_r(array_search('y', ['x', 'y'], true), true), "\n";
