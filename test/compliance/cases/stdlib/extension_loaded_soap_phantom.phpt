--TEST--
stdlib extension_loaded('soap') false without host php-soap / forward profile (#22859, ext/soap/soap.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('soap'), "\n";
echo 'in_list=', (int) in_array('soap', get_loaded_extensions(), true), "\n";
echo 'funcs=', (int) (false !== get_extension_funcs('soap')), "\n";
echo 'is_soap_fault=', (int) function_exists('is_soap_fault'), "\n";
echo 'use_soap_error_handler=', (int) function_exists('use_soap_error_handler'), "\n";
echo 'SoapClient=', (int) class_exists('SoapClient', false), "\n";
echo 'SoapServer=', (int) class_exists('SoapServer', false), "\n";
echo 'SoapFault=', (int) class_exists('SoapFault', false), "\n";
?>
--EXPECT--
loaded=0
in_list=0
funcs=0
is_soap_fault=0
use_soap_error_handler=0
SoapClient=0
SoapServer=0
SoapFault=0
