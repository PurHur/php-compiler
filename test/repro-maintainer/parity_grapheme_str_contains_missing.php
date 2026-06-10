<?php

declare(strict_types=1);

var_export(function_exists('grapheme_str_contains'));
echo "\n";
var_export(grapheme_str_contains('hello', 'ell'));
echo "\n";
var_export(grapheme_str_contains('café', 'é'));
echo "\n";
