<?php
// #26320 — readdir/tempnam/gethostbynamel/sys_getloadavg |false; opendir directory.
foreach (['readdir', 'tempnam', 'gethostbynamel', 'sys_getloadavg'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' ret=', $r->hasReturnType() ? (string) $r->getReturnType() : 'NONE', "\n";
}
$r = new ReflectionFunction('opendir');
foreach ($r->getParameters() as $p) {
    echo 'opendir ', $p->getName(), "\n";
}
$dh = opendir(directory: sys_get_temp_dir());
echo 'named_ok=', (false !== $dh) ? '1' : '0', "\n";
if (false !== $dh) {
    closedir($dh);
}
