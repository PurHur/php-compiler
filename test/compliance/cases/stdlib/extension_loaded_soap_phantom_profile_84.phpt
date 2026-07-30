--TEST--
stdlib soap withheld under PHP_COMPILER_PROFILE=8.4 without host php-soap (#25165)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('soap'), "\n";
echo 'SoapClient=', (int) class_exists('SoapClient', false), "\n";
echo 'SoapServer=', (int) class_exists('SoapServer', false), "\n";
echo 'SoapFault=', (int) class_exists('SoapFault', false), "\n";
?>
--EXPECT--
loaded=0
SoapClient=0
SoapServer=0
SoapFault=0
