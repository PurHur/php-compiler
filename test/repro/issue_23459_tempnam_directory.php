<?php
// Repro #23459 — tempnam Reflection/named directory (ext/standard/file.stub.php)
$r = new ReflectionFunction('tempnam');
echo 'names=';
foreach ($r->getParameters() as $p) {
    echo $p->getName(), ',';
}
echo "\n";
try {
    $p = tempnam(directory: sys_get_temp_dir(), prefix: 'pc');
    echo 'directory=', is_string($p) ? 'ok' : 'bad', "\n";
    @unlink($p);
} catch (Throwable $e) {
    echo 'directory ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $p = tempnam(dir: sys_get_temp_dir(), prefix: 'pc');
    echo 'dir=', is_string($p) ? 'ok' : 'bad', "\n";
    @unlink($p);
} catch (Throwable $e) {
    echo 'dir ERR=', get_class($e), ': ', $e->getMessage(), "\n";
}
$pos = tempnam(sys_get_temp_dir(), 'pc');
echo 'positional=', is_string($pos) ? 'ok' : 'bad', "\n";
@unlink($pos);
