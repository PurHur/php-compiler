--TEST--
stdlib extension_loaded('soap') false without host php-soap (#22859/#25165, ext/soap/soap.c)
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
echo 'SoapUrl=', (int) class_exists('Soap\\Url', false), "\n";
echo 'SoapSdl=', (int) class_exists('Soap\\Sdl', false), "\n";
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
SoapUrl=0
SoapSdl=0
