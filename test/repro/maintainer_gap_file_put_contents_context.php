<?php

declare(strict_types=1);

$ctx = stream_context_create([]);
$path = sys_get_temp_dir() . '/phpc-fpc-context-' . getmypid() . '.txt';
$result = file_put_contents($path, 'x', 0, $ctx);
echo 'file_put_contents_context_ok=', false !== $result ? '1' : '0', "\n";
if (false !== $result) {
    @unlink($path);
}

$path2 = sys_get_temp_dir() . '/phpc-fpc-named-' . getmypid() . '.txt';
$result2 = file_put_contents(filename: $path2, data: 'y', context: $ctx);
echo 'file_put_contents_named_context_ok=', false !== $result2 ? '1' : '0', "\n";
if (false !== $result2) {
    @unlink($path2);
}
