--TEST--
stream_resolve_include_path() resolves via include_path (issue #6051)
--FILE--
<?php
$inc = sys_get_temp_dir() . '/phpc_inc_' . getmypid();
@mkdir($inc);
file_put_contents($inc . '/only_here.php', 'marker');
$old = set_include_path($inc);
$resolved = stream_resolve_include_path('only_here.php');
$missing = stream_resolve_include_path('not_present_' . getmypid() . '.php');
set_include_path($old);
@unlink($inc . '/only_here.php');
@rmdir($inc);
echo function_exists('stream_resolve_include_path') ? "yes\n" : "no\n";
echo is_string($resolved) ? "found\n" : "notfound\n";
echo false === $missing ? "false\n" : "bad\n";
echo get_include_path() === $old ? "restored\n" : "notrestored\n";
--EXPECT--
yes
found
false
restored
--CREDITS--
PurHur/php-compiler issue #6051
