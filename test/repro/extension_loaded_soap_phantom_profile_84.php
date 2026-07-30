<?php
/** Repro #25165 — soap withheld under PROFILE=8.4 when host Zend lacks php-soap. */
echo 'ext=', extension_loaded('soap') ? 'yes' : 'no', "\n";
echo 'SoapClient=', class_exists('SoapClient', false) ? 'yes' : 'no', "\n";
echo 'SoapServer=', class_exists('SoapServer', false) ? 'yes' : 'no', "\n";
echo 'SoapFault=', class_exists('SoapFault', false) ? 'yes' : 'no', "\n";
