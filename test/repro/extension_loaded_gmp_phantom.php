<?php
/** Repro #22860 — gmp withheld on default/reference profile without host php-gmp. */
echo 'ext=', extension_loaded('gmp') ? 'yes' : 'no', "\n";
echo 'gmp_add=', function_exists('gmp_add') ? 'yes' : 'no', "\n";
echo 'GMP=', class_exists('GMP', false) ? 'yes' : 'no', "\n";
