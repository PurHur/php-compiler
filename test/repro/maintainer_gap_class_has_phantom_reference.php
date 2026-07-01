<?php

declare(strict_types=1);

// Zend 8.2 reference profile: class_has_* undefined (#14722).
$chm = function_exists('class_has_method');
$chp = function_exists('class_has_property');
$chc = function_exists('class_has_constant');
echo ($chm ? 'chm_fail' : 'chm_ok')."\n";
echo ($chp ? 'chp_fail' : 'chp_ok')."\n";
echo ($chc ? 'chc_fail' : 'chc_ok')."\n";
