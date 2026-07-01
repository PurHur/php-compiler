<?php

declare(strict_types=1);

// Zend var_export finite doubles use decimal form, not dtoa scientific (#14707).
echo var_export(150.0, true), "\n";
echo var_export(100.0, true), "\n";
echo var_export(sscanf('1.5e2', '%f')[0], true), "\n";
echo var_export(1.0E-10, true), "\n";
