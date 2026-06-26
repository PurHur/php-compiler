<?php

declare(strict_types=1);

// Maintainer gap #12176 — leading backslash global names (dynamic, php-src-strict).
$name = '\\array_map';
var_export(function_exists($name));
echo "\n";
var_export(class_exists('\\stdClass'));
echo "\n";
var_export(defined('\\PHP_VERSION'));
