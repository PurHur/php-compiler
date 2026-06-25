--TEST--
stdlib file_put_contents() optional stream context arg (#11494, ext/standard/file.c)
--FILE--
<?php
$ctx = stream_context_create([]);
$path = sys_get_temp_dir() . '/phpc-fpc-' . getmypid() . '.txt';
$written = file_put_contents($path, 'x', 0, $ctx);
echo false !== $written ? "context\n" : "context-bad\n";
@unlink($path);
$path2 = sys_get_temp_dir() . '/phpc-fpc-named-' . getmypid() . '.txt';
$written2 = file_put_contents(filename: $path2, data: 'y', context: $ctx);
echo false !== $written2 ? "named\n" : "named-bad\n";
@unlink($path2);
--EXPECT--
context
named
