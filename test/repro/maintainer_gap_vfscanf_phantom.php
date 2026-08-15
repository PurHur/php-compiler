<?php
declare(strict_types=1);

/** Issue #26758 — vfscanf() phantom vs php-src (sscanf/fscanf only). */
echo 'exists=', function_exists('vfscanf') ? '1' : '0', "\n";
$defs = get_defined_functions()['internal'];
echo 'defined=', in_array('vfscanf', $defs, true) ? '1' : '0', "\n";
echo 'sscanf=', function_exists('sscanf') ? '1' : '0', "\n";
echo 'fscanf=', function_exists('fscanf') ? '1' : '0', "\n";
