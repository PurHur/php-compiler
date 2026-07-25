<?php
/**
 * Repro #22860 — extension_loaded('gmp') / function_exists('gmp_add') phantom on reference profile.
 *
 * Zend (no ext-gmp): both false.
 * VM reference profile: both false after fix.
 * Forward: PHP_COMPILER_PROFILE=8.4 php bin/vm.php this file → both true + gmp_add works.
 */
declare(strict_types=1);

echo 'loaded=', extension_loaded('gmp') ? 'yes' : 'no', "\n";
echo 'gmp_add=', function_exists('gmp_add') ? 'yes' : 'no', "\n";
echo 'class=', class_exists('GMP', false) ? 'yes' : 'no', "\n";
if (function_exists('gmp_add')) {
    echo 'sum=', gmp_strval(gmp_add('2', '3')), "\n";
}
