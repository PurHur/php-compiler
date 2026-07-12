--TEST--
inotify_add_watch() enum pathname TypeError (#6410)
--SKIPIF--
<?php
if (!function_exists('inotify_init')) {
    die('skip inotify unavailable');
}
?>
--FILE--
<?php
declare(strict_types=1);
enum Es: string { case A = 'x'; }
$fd = inotify_init();
try {
    inotify_add_watch($fd, Es::A, IN_MODIFY);
    echo "NO_ERROR\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
TypeError: inotify_add_watch(): Argument #2 ($pathname) must be of type string, Es given
