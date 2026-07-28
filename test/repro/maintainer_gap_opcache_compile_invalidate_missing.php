<?php
/**
 * Repro #23834 — opcache_compile_file / invalidate / is_script_cached must exist
 * and return false when OPcache is not active (Zend disabled semantics).
 */
foreach (['opcache_compile_file', 'opcache_invalidate', 'opcache_is_script_cached'] as $f) {
    echo $f, '=', function_exists($f) ? 'yes' : 'NO', "\n";
}
$f = sys_get_temp_dir() . '/opcache_probe_' . getmypid() . '.php';
file_put_contents($f, "<?php return 1;\n");
echo 'compile=', var_export(opcache_compile_file($f), true), "\n";
echo 'cached=', var_export(opcache_is_script_cached($f), true), "\n";
echo 'invalidate=', var_export(opcache_invalidate($f), true), "\n";
echo 'named=', var_export(opcache_compile_file(filename: $f), true), "\n";
@unlink($f);
