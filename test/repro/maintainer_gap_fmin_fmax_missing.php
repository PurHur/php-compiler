<?php
// Issue #11728 — fmin()/fmax() PHP 8.4 variadic float min/max.
echo 'fmin='.(function_exists('fmin') ? 'yes' : 'missing'), "\n";
echo 'fmax='.(function_exists('fmax') ? 'yes' : 'missing'), "\n";
echo 'fpow='.(function_exists('fpow') ? fpow(2, 3) : 'missing'), "\n";
echo 'fmin_result=', fmin(1.5, 2.0, 0.5), "\n";
echo 'fmax_result=', fmax(1.5, 2.0, 3.0), "\n";
