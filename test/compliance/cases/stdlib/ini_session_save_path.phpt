--TEST--
stdlib ini_get('session.save_path') — Zend default path string (#11499, ext/session/session.c)
--FILE--
<?php
$path = ini_get('session.save_path');
echo is_string($path) ? "string\n" : "not-string\n";
echo $path === '/var/lib/php/sessions' ? "default\n" : "default-bad\n";
$old = ini_set('session.save_path', '/tmp/phpc-ini-session');
echo ini_get('session.save_path') === '/tmp/phpc-ini-session' ? "set-ok\n" : "set-bad\n";
if (false !== $old) {
    ini_set('session.save_path', $old);
}
--EXPECT--
string
default
set-ok
