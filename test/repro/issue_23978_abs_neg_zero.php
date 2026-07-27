<?php

declare(strict_types=1);

// #23978 — abs(-0.0) must clear the sign bit (php-src ext/standard/math.c / fabs).
var_export(abs(-0.0));
echo "\n";
echo json_encode(abs(-0.0)), "\n";
var_export(abs(0.0));
echo "\n";
echo abs(-2.5), "\n";
