<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: file_get_contents() on value-boxed concat paths (SourceBundler pattern).
 */

$from = 'test/bootstrap-aot/deploy_path_data/templates/marker.php';
$base = dirname($from);
$candidate = $base.'/marker.php';
$data = file_get_contents($candidate);
echo is_string($data) ? '1' : '0';
echo "\n";
$literal = file_get_contents('test/bootstrap-aot/deploy_path_data/templates/marker.php');
echo is_string($literal) ? '1' : '0';
echo "\n";
