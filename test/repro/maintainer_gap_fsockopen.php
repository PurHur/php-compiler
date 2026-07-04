<?php

declare(strict_types=1);

$errno = 0;
$errstr = '';
$fp = @fsockopen('127.0.0.1', 9, $errno, $errstr, 1);
var_export(function_exists('fsockopen'));
echo "\n";
var_export(is_resource($fp));
echo "\n";
var_export($errno);
echo "\n";
var_export($errstr);
echo "\n";
