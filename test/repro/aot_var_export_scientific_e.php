<?php
// #33901 — thin AOT var_export scientific floats must use zend_gcvt uppercase E
echo var_export(PHP_INT_MAX + 1, true), "\n";
echo var_export(1.0e100, true), "\n";
echo var_export(-9.223372036854776e18, true), "\n";
