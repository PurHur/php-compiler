<?php

declare(strict_types=1);

/**
 * Issue #28404 — under PHP_COMPILER_PROFILE=8.4, header_list must stay absent
 * (php-src never advertises it; only headers_list()).
 */
echo 'exists=', function_exists('header_list') ? '1' : '0', PHP_EOL;
echo 'defined=', in_array('header_list', get_defined_functions()['internal'] ?? [], true) ? '1' : '0', PHP_EOL;
echo 'headers=', function_exists('headers_list') ? '1' : '0', PHP_EOL;
