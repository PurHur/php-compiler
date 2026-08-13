<?php
/**
 * session_destroy / tmpfile excess argc → ArgumentCountError (#30676).
 * php-src: ext/session/session.c, main/streams/streams.c
 */
foreach (['session_destroy', 'tmpfile'] as $f) {
    try {
        $f(1);
        echo $f, ":NO_THROW\n";
    } catch (Throwable $e) {
        echo $f, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}
error_reporting(E_ALL & ~E_WARNING);
session_destroy();
echo "session_destroy_ok\n";
$h = tmpfile();
echo (false !== $h) ? "tmpfile_ok\n" : "tmpfile_fail\n";
