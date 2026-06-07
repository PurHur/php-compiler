<?php
declare(strict_types=1);
echo function_exists('stream_is_local') ? '1' : '0', "\n";
$memory = fopen('php://memory', 'r+');
echo stream_is_local($memory) ? '1' : '0', "\n";
fclose($memory);
$path = sys_get_temp_dir() . '/phpc_stream_is_local_' . (string) getmypid() . '.txt';
$fp = fopen($path, 'w');
echo stream_is_local($fp) ? '1' : '0', "\n";
fclose($fp);
@unlink($path);
