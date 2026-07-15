--TEST--
get_defined_constants(true) user bucket empty when no define() — IN_* withheld when inotify unloaded (#18041, #18048, basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

$c = get_defined_constants(true);
$userCount = isset($c['user']) ? count($c['user']) : 0;
$inotifyCount = isset($c['inotify']) ? count($c['inotify']) : 0;
$iconvOk = !extension_loaded('iconv')
    || (isset($c['iconv']['ICONV_MIME_DECODE_STRICT']) && isset($c['iconv']['ICONV_MIME_DECODE_CONTINUE_ON_ERROR']));
echo $userCount === 0 ? "user_ok\n" : "user_bad\n";
echo $iconvOk ? "iconv_ok\n" : "iconv_bad\n";
echo extension_loaded('inotify') ? ($inotifyCount >= 24 ? "inotify_ok\n" : "inotify_bad\n") : ($inotifyCount === 0 ? "inotify_ok\n" : "inotify_bad\n");
echo extension_loaded('inotify') ? (defined('IN_ACCESS') ? "in_access_ok\n" : "in_access_bad\n") : (!defined('IN_ACCESS') ? "in_access_ok\n" : "in_access_bad\n");
--EXPECT--
user_ok
iconv_ok
inotify_ok
in_access_ok
