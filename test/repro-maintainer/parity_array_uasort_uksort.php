<?php

declare(strict_types=1);

// #22372 — php-src has uasort()/uksort() only; array_* names must not exist.
var_export(function_exists('array_uasort'));
echo "\n";
var_export(function_exists('array_uksort'));
echo "\n";
var_export(function_exists('uasort'));
echo "\n";
var_export(function_exists('uksort'));
echo "\n";
