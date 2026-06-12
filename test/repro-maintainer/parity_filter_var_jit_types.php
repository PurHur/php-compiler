<?php

declare(strict_types=1);

var_export(filter_var(true, FILTER_VALIDATE_BOOLEAN));
echo PHP_EOL;
var_export(filter_var(3.14, FILTER_VALIDATE_FLOAT));
echo PHP_EOL;
var_export(filter_var('no', FILTER_VALIDATE_BOOLEAN));
echo PHP_EOL;
