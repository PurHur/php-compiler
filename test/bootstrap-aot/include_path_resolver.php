<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: path resolution checks (relative + absolute paths).
 * Exercises dirname/concat/is_file/realpath lowering used by IncludePathResolver (#816).
 */

$from = 'test/bootstrap-aot/deploy_path_data/templates/marker.php';
$base = dirname($from);

echo "1\n";
echo (is_file($base.'/marker.php') ? '1' : '0')."\n";
echo (is_file($base.'/missing.php') ? '0' : '1')."\n";
echo (is_file('/etc/hosts') ? '1' : '0')."\n";
echo (is_file('/nonexistent/path.php') ? '0' : '1')."\n";
