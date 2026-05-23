<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: file_exists() on value-boxed concat paths.
 */

$dir = __DIR__.'/deploy_path_data/templates';
$path = $dir.'/marker.php';
echo file_exists($path) ? '1' : '0';
echo "\n";
echo file_exists(__DIR__.'/deploy_path_data/templates/marker.php') ? '1' : '0';
echo "\n";
