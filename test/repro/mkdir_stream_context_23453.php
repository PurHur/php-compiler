<?php
$dir = sys_get_temp_dir() . '/pc_mkdir_ctx_' . getmypid();
@rmdir($dir);
$ctx = stream_context_create([]);
try {
    $ok = mkdir($dir, 0777, false, $ctx);
    echo ($ok && is_dir($dir)) ? "ok\n" : "bad\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
@rmdir($dir);
$r = new ReflectionFunction('mkdir');
echo implode(',', array_map(fn($p) => $p->getName(), $r->getParameters())), "\n";
$dir2 = sys_get_temp_dir() . '/pc_mkdir_ctx_named_' . getmypid();
@rmdir($dir2);
try {
    $ok = mkdir(directory: $dir2, permissions: 0777, recursive: false, context: $ctx);
    echo ($ok && is_dir($dir2)) ? "named_ok\n" : "named_bad\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
@rmdir($dir2);
