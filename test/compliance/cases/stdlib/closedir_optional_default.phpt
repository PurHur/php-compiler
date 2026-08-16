--TEST--
stdlib closedir() optional dir_handle / default directory + Reflection void (#27999)
--FILE--
<?php
$rf = new ReflectionFunction('closedir');
echo 'return=', $rf->hasReturnType() ? (string) $rf->getReturnType() : '-', "\n";
$p = $rf->getParameters()[0];
echo 'default=', $p->isDefaultValueAvailable() ? var_export($p->getDefaultValue(), true) : '-', "\n";

try {
    closedir();
    echo "bare_noopen_fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

$dir = sys_get_temp_dir() . '/phpc_closedir_opt_' . getmypid();
mkdir($dir);
$dh = opendir($dir);
readdir($dh);
closedir();
try {
    readdir($dh);
    echo "still_open\n";
} catch (TypeError $e) {
    echo "closed\n";
}
rmdir($dir);
?>
--EXPECT--
return=void
default=NULL
No resource supplied
closed
