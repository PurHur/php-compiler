<?php
/** Repro #22859 — soap withheld on default/reference profile without host php-soap. */
echo 'ext=', extension_loaded('soap') ? 'yes' : 'no', "\n";
echo 'SoapClient=', class_exists('SoapClient', false) ? 'yes' : 'no', "\n";
echo 'SoapServer=', class_exists('SoapServer', false) ? 'yes' : 'no', "\n";
echo 'SoapFault=', class_exists('SoapFault', false) ? 'yes' : 'no', "\n";
echo 'is_soap_fault=', function_exists('is_soap_fault') ? 'yes' : 'no', "\n";
