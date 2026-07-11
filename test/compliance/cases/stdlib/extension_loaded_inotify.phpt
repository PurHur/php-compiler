--TEST--
stdlib extension_loaded('inotify') false when libc inotify unavailable (#18049, ext/inotify/php_inotify.c)
--FILE--
<?php
declare(strict_types=1);

echo 'loaded=', (int) extension_loaded('inotify'), "\n";
echo 'funcs=', (int) function_exists('inotify_init'), "\n";
$c = get_defined_constants(true);
echo 'bucket=', isset($c['inotify']) ? count($c['inotify']) : 0, "\n";
--EXPECT--
loaded=0
funcs=0
bucket=0
