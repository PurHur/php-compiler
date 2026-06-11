<?php

declare(strict_types=1);

// AOT compile-only: PhpInputFilter enum + filter_input() (#7284).
var_export(enum_exists('PhpInputFilter', false));
echo "\n";
var_export(filter_input(PhpInputFilter::Get, 'missing', FILTER_VALIDATE_INT) === null);
echo "\n";
