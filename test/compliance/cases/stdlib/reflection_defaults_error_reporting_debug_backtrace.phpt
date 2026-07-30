--TEST--
Stdlib: error_reporting/debug_backtrace Reflection defaults (#25278, basic_functions.stub.php)
--FILE--
<?php
$er = (new ReflectionFunction('error_reporting'))->getParameters()[0];
echo 'error_reporting ', $er->getName(), ' ',
    ($er->isOptional() ? 'opt' : 'req'), ' ',
    ($er->isDefaultValueAvailable() ? var_export($er->getDefaultValue(), true) : 'N/A'), "\n";

$bt = new ReflectionFunction('debug_backtrace');
$options = $bt->getParameters()[0];
$limit = $bt->getParameters()[1];
echo 'debug_backtrace ', $options->getName(), ' ',
    ($options->isOptional() ? 'opt' : 'req'), ' ',
    ($options->isDefaultValueAvailable() ? var_export($options->getDefaultValue(), true) : 'N/A'), "\n";
echo 'debug_backtrace ', $limit->getName(), ' ',
    ($limit->isOptional() ? 'opt' : 'req'), ' ',
    ($limit->isDefaultValueAvailable() ? var_export($limit->getDefaultValue(), true) : 'N/A'), "\n";

echo is_int(error_reporting()) ? "error_reporting-call-ok\n" : "error_reporting-call-fail\n";
echo is_array(debug_backtrace()) ? "debug_backtrace-call-ok\n" : "debug_backtrace-call-fail\n";
--EXPECT--
error_reporting error_level opt NULL
debug_backtrace options opt 1
debug_backtrace limit opt 0
error_reporting-call-ok
debug_backtrace-call-ok
