<?php
// Repro #26117 — ftok Reflection + named args match php-src stub (filename/project_id)
$path = tempnam(sys_get_temp_dir(), 'ftok26117');
$names = [];
foreach ((new ReflectionFunction('ftok'))->getParameters() as $p) {
    $names[] = $p->getName();
}
$key = ftok(filename: $path, project_id: 'a');
$positional = ftok($path, 'a');
$legacyRejected = false;
try {
    ftok(pathname: $path, proj: 'a');
} catch (Error $e) {
    $legacyRejected = str_contains($e->getMessage(), 'pathname')
        || str_contains($e->getMessage(), 'proj');
}
@unlink($path);
$ok = ['filename', 'project_id'] === $names
    && is_int($key)
    && $key === $positional
    && $legacyRejected;
echo $ok ? "ok\n" : "fail\n";
