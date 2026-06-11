<?php

declare(strict_types=1);

// Issue #7284 repro: PhpInputFilter enum + filter_input() parity.
var_export(enum_exists('PhpInputFilter', false));
echo "\n";
var_export(filter_input(INPUT_GET, 'missing', FILTER_VALIDATE_INT));
echo "\n";
var_export(filter_input(PhpInputFilter::Get, 'missing', FILTER_VALIDATE_INT));
echo "\n";
