--TEST--
stream_resolve_include_path() JIT/AOT (issue #6051)
--FILE--
<?php
$inc = sys_get_temp_dir() . '/phpc_inc_' . getmypid();
@mkdir($inc);
file_put_contents($inc . '/only_here.php', 'marker');
$old = set_include_path($inc);
$resolved = stream_resolve_include_path('only_here.php');
set_include_path($old);
@unlink($inc . '/only_here.php');
@rmdir($inc);
echo is_string($resolved) ? "found\n" : "notfound\n";
--EXPECT--
found
--CREDITS--
PurHur/php-compiler issue #6051
