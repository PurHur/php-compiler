--TEST--
stdlib readdir() optional dir_handle / default directory (#31450)
--FILE--
<?php
$rf = new ReflectionFunction('readdir');
echo 'default=', $rf->getParameters()[0]->isDefaultValueAvailable()
    ? var_export($rf->getParameters()[0]->getDefaultValue(), true)
    : '-', "\n";

try {
    readdir();
    echo "bare_noopen_fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$dir = sys_get_temp_dir() . '/phpc_readdir_opt_' . getmypid();
mkdir($dir);
$dh = opendir($dir);
$e = readdir();
echo is_string($e) ? "bare_ok\n" : "bare_fail\n";
closedir($dh);
rmdir($dir);
?>
--EXPECT--
default=NULL
No resource supplied
bare_ok
