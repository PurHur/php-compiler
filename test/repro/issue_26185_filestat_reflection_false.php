<?php
// #26185 — filesize/filemtime/glob/scandir/realpath Reflection return |false (php-src file.stub.php)
foreach (['filesize', 'filemtime', 'glob', 'scandir', 'realpath'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ret=', (string) $r->getReturnType(), "\n";
}
echo 'filesize_missing=', var_export(@filesize('/no/such/phpc-filestat-26185.txt'), true), "\n";
echo 'realpath_missing=', var_export(@realpath('/no/such/phpc-filestat-26185.txt'), true), "\n";
