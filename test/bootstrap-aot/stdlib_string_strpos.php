<?php
declare(strict_types=1);
$hay = 'test/bootstrap-aot/' . 'deploy_path_data/marker.php';
echo false !== strpos($hay, 'marker') ? '1' : '0';
echo "\n";
