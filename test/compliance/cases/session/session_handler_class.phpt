--TEST--
session SessionHandler class — default files save handler object (#19246, ext/session/mod_user_class.c)
--FILE--
<?php
ob_start();
$dir = sys_get_temp_dir().'/phpc_session_handler_'.getmypid();
@mkdir($dir, 0700, true);
putenv('PHP_COMPILER_SESSION_DIR='.$dir);

$exists = class_exists('SessionHandler', false);
$h = new SessionHandler();
$iface = $h instanceof SessionHandlerInterface;
$idIface = $h instanceof SessionIdInterface;
session_set_save_handler($h, true);
$ok = session_start();
$_SESSION['k'] = 'v';
session_write_close();
session_start();
$got = $_SESSION['k'] ?? null;
$module = session_module_name();
session_write_close();

echo $exists ? 'exists' : 'missing', "\n";
echo $iface ? 'iface' : 'no-iface', "\n";
echo $idIface ? 'idiface' : 'no-idiface', "\n";
echo $ok ? 'start-ok' : 'start-fail', "\n";
echo $module, "\n";
echo is_string($got) ? $got : 'missing', "\n";
--EXPECT--
exists
iface
idiface
start-ok
user
v
