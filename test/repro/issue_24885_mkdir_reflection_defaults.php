<?php
/**
 * Issue #24885 — mkdir Reflection permissions=0777, context=NULL (ext/standard/file.stub.php).
 */
$r = new ReflectionFunction('mkdir');
foreach ($r->getParameters() as $p) {
    echo $p->getName();
    if ($p->isDefaultValueAvailable()) {
        echo '=', var_export($p->getDefaultValue(), true);
    } else {
        echo ' (no default)';
    }
    echo ' opt=', (int) $p->isOptional(), "\n";
}
$dir = sys_get_temp_dir().'/phpc_mkdir_ref_'.getmypid();
@rmdir($dir);
$ok = mkdir(directory: $dir, permissions: 0755, recursive: false);
echo 'named_ok=', (int) ($ok && is_dir($dir)), "\n";
if (is_dir($dir)) {
    rmdir($dir);
}
