--TEST--
stdlib stream_context notification Closure + file_get_contents (#22815, re-#19696)
--FILE--
<?php
declare(strict_types=1);

$path = tempnam(sys_get_temp_dir(), 'phpc');
file_put_contents($path, 'hi');
$ctx = stream_context_create();
$cb = static function (): void {};
stream_context_set_params($ctx, ['notification' => $cb]);
try {
    $data = file_get_contents($path, false, $ctx);
    echo 'data=', $data, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

// create(..., $params) with notification Closure must also leave file reads intact
$ctx2 = stream_context_create([], ['notification' => $cb]);
try {
    $data2 = file_get_contents($path, false, $ctx2);
    echo 'create_data=', $data2, "\n";
} catch (Throwable $e) {
    echo 'create_', get_class($e), ': ', $e->getMessage(), "\n";
}

unlink($path);
--EXPECT--
data=hi
create_data=hi
