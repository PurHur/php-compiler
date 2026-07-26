<?php
$dir = sys_get_temp_dir() . '/pc_rmdir_ctx_' . getmypid();
@mkdir($dir);
$ctx = stream_context_create([]);
try {
    var_export(rmdir($dir, $ctx));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    @rmdir($dir);
}
$r = new ReflectionFunction('rmdir');
$names = [];
foreach ($r->getParameters() as $p) {
    $names[] = $p->getName();
}
echo implode(',', $names), "\n";
try {
    $d2 = sys_get_temp_dir() . '/pc_rmdir_named_' . getmypid();
    @mkdir($d2);
    var_export(rmdir(directory: $d2));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    @rmdir($d2);
}
try {
    $d3 = sys_get_temp_dir() . '/pc_rmdir_named_ctx_' . getmypid();
    @mkdir($d3);
    var_export(rmdir(directory: $d3, context: $ctx));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    @rmdir($d3);
}
