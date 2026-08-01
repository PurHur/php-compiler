--TEST--
stdlib filesize/filemtime/glob/scandir/realpath Reflection return unions (#26185)
--FILE--
<?php
foreach (['filesize', 'filemtime', 'glob', 'scandir', 'realpath'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', (string) $r->getReturnType(), "\n";
}
echo 'filesize_missing=', var_export(@filesize('/no/such/phpc-filestat-26185.txt'), true), "\n";
echo 'realpath_missing=', var_export(@realpath('/no/such/phpc-filestat-26185.txt'), true), "\n";
?>
--EXPECT--
filesize ret=int|false
filemtime ret=int|false
glob ret=array|false
scandir ret=array|false
realpath ret=string|false
filesize_missing=false
realpath_missing=false
