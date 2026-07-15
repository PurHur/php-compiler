<?php
echo ob_start() ? '' : '';
echo ob_get_clean() === '' ? '' : '';

ob_start();
var_export(null);
$literal = ob_get_clean();
echo $literal, "\n";

$v = null;
ob_start();
var_export($v);
$variable = ob_get_clean();
echo $variable, "\n";
