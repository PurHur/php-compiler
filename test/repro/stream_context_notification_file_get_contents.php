<?php
// Zend: notification Closure on context is fine for file_get_contents.
// VM: LogicException http_build_query() value type not supported (re-#19696).
$path = tempnam(sys_get_temp_dir(), 'phpc');
file_put_contents($path, 'hi');
$ctx = stream_context_create();
stream_context_set_params($ctx, ['notification' => static function (): void {}]);
try {
    $data = file_get_contents($path, false, $ctx);
    echo 'data=', $data, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
unlink($path);
