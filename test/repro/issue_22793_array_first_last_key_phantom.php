<?php
/** Repro #22793 — array_first_key/array_last_key phantoms: not in php-src ext/standard/array.c. */
echo 'array_first_key=', function_exists('array_first_key') ? '1' : '0', PHP_EOL;
echo 'array_last_key=', function_exists('array_last_key') ? '1' : '0', PHP_EOL;
echo 'array_key_first=', function_exists('array_key_first') ? '1' : '0', PHP_EOL;
echo 'array_key_last=', function_exists('array_key_last') ? '1' : '0', PHP_EOL;
echo 'array_first=', function_exists('array_first') ? '1' : '0', PHP_EOL;
