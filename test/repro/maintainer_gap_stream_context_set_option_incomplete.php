<?php
$c = stream_context_create();
try {
    var_export(stream_context_set_option($c, 'http'));
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(stream_context_set_option($c, 'http', 'method'));
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
var_export(stream_context_set_option($c, ['http' => ['method' => 'GET']]));
echo "\n";
var_export(stream_context_set_option($c, 'http', 'method', 'GET'));
echo "\n";
