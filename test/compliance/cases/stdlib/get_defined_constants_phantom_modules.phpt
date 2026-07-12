--TEST--
get_defined_constants(true) omits inotify bucket when extension_loaded() is false (#18048, #18083, basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

$phantom = [];
foreach (['inotify'] as $ext) {
    if (!extension_loaded($ext) && isset(get_defined_constants(true)[$ext])) {
        $phantom[] = $ext;
    }
}
echo [] === $phantom ? "phantom_ok\n" : "phantom_bad\n";
echo extension_loaded('inotify') ? "inotify_loaded\n" : "inotify_unloaded\n";
echo function_exists('inotify_init') ? "inotify_fn_bad\n" : "inotify_fn_ok\n";
--EXPECT--
phantom_ok
inotify_unloaded
inotify_fn_ok
