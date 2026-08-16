--TEST--
stdlib rewinddir() optional dir_handle + Reflection void (#31451, #28308)
--FILE--
<?php
$rf = new ReflectionFunction('rewinddir');
echo 'return=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '-', "\n";
echo 'default=', $rf->getParameters()[0]->isDefaultValueAvailable()
    ? var_export($rf->getParameters()[0]->getDefaultValue(), true)
    : '-', "\n";

try {
    rewinddir();
    echo "bare_noopen_fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$dir = sys_get_temp_dir() . '/phpc_rewinddir_opt_' . getmypid();
mkdir($dir);
$dh = opendir($dir);
$a = readdir($dh);
rewinddir();
$b = readdir($dh);
echo ($a === $b) ? "bare_ok\n" : "bare_fail\n";
closedir($dh);
rmdir($dir);
?>
--EXPECT--
return=void
default=NULL
No resource supplied
bare_ok
