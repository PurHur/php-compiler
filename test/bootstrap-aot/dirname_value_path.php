<?php

declare(strict_types=1);

/**
 * Bootstrap AOT: dirname() on value-boxed concat paths.
 */

$from = 'test/bootstrap-aot/deploy_path_data/templates/marker.php';
$base = dirname($from);
echo is_string($base) ? '1' : '0';
echo "\n";
echo dirname(__DIR__.'/deploy_path_data/templates/marker.php') === $base ? '1' : '0';
echo "\n";
