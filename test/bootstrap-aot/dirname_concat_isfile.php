<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: is_file() on value-boxed dirname/concat paths (IncludePathResolver pattern).
 */

$from = 'test/bootstrap-aot/deploy_path_data/templates/marker.php';
$base = dirname($from);
$candidate = $base.'/marker.php';
echo is_file($candidate) ? '1' : '0';
echo "\n";
echo is_file('test/bootstrap-aot/deploy_path_data/templates/marker.php') ? '1' : '0';
echo "\n";
