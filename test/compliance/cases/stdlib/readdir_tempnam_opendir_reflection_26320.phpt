--TEST--
readdir/tempnam/gethostbynamel/sys_getloadavg Reflection |false; opendir directory (#26320)
--FILE--
<?php
foreach (['readdir', 'tempnam', 'gethostbynamel', 'sys_getloadavg'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}
$r = new ReflectionFunction('opendir');
foreach ($r->getParameters() as $p) {
    echo 'opendir ', $p->getName(), "\n";
}
$dh = opendir(directory: __DIR__);
echo 'named_ok=', (false !== $dh) ? '1' : '0', "\n";
if (false !== $dh) {
    closedir($dh);
}
?>
--EXPECT--
readdir ret=string|false
tempnam ret=string|false
gethostbynamel ret=array|false
sys_getloadavg ret=array|false
opendir directory
opendir context
named_ok=1
