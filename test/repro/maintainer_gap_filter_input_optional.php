<?php

declare(strict_types=1);

// Issue #14152: filter_input() two-argument form — optional $filter defaults to FILTER_DEFAULT.
echo 'two_arg:';
var_export(filter_input(INPUT_GET, 'missing'));
echo "\n";
echo 'three_arg:';
var_export(filter_input(INPUT_GET, 'missing', FILTER_DEFAULT));
echo "\n";
