--TEST--
stdlib extension_loaded('stats') false without host pecl-stats (#26743)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('stats'), "\n";
echo 'in_list=', (int) in_array('stats', get_loaded_extensions(), true), "\n";
echo 'funcs=', (int) (false !== get_extension_funcs('stats')), "\n";
echo 'cov=', (int) function_exists('stats_covariance'), "\n";
echo 'sd=', (int) function_exists('stats_standard_deviation'), "\n";
echo 'var=', (int) function_exists('stats_variance'), "\n";
?>
--EXPECT--
loaded=0
in_list=0
funcs=0
cov=0
sd=0
var=0
