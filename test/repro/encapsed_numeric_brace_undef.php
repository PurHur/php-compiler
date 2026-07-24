<?php
// Repro #22776 — undefined encapsed ${1} must warn like Zend
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "undef=[${1}]\n";

${1} = 'ONE';
echo "defined=[${1}]\n";

$missing = null;
unset($missing);
echo "named=[${missing}]\n";
