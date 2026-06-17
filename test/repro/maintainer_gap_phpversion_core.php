<?php

$core = phpversion('Core');
$lower = phpversion('core');
$std = phpversion('standard');
echo 'core_type:' . gettype($core) . "\n";
echo 'core_val:' . var_export($core, true) . "\n";
echo 'lower_type:' . gettype($lower) . "\n";
echo 'lower_val:' . var_export($lower, true) . "\n";
echo 'std_val:' . var_export($std, true) . "\n";
