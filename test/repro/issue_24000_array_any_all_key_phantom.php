<?php
/** Repro #24000 — array_any_key/array_all_key phantoms: not in php-src ext/standard/array.c. */
echo 'array_any_key=', function_exists('array_any_key') ? '1' : '0', PHP_EOL;
echo 'array_all_key=', function_exists('array_all_key') ? '1' : '0', PHP_EOL;
echo 'array_any=', function_exists('array_any') ? '1' : '0', PHP_EOL;
echo 'array_all=', function_exists('array_all') ? '1' : '0', PHP_EOL;
echo 'array_find=', function_exists('array_find') ? '1' : '0', PHP_EOL;
echo 'array_find_key=', function_exists('array_find_key') ? '1' : '0', PHP_EOL;
