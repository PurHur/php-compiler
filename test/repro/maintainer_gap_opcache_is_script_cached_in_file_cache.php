<?php
$fns = [
    'opcache_is_script_cached',
    'opcache_is_script_cached_in_file_cache',
];
foreach ($fns as $f) {
    echo $f, '=', function_exists($f) ? 'Y' : 'N', PHP_EOL;
}
if (function_exists('opcache_is_script_cached_in_file_cache')) {
    $f = sys_get_temp_dir() . '/opc_file_cache_' . getmypid() . '.php';
    file_put_contents($f, "<?php return 1;\n");
    var_export(opcache_is_script_cached_in_file_cache($f));
    echo PHP_EOL;
    @unlink($f);
}
