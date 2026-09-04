<?php

declare(strict_types=1);

/**
 * #36382 — FsDirRuntime __compiler_tempnam bridge must not steal BBs into the
 * in-flight user function (Slim IncludeHelper NestedJIT). php-src: ext/standard/file.c
 */
$dir = sys_get_temp_dir();
$path = tempnam($dir, 'phpc36382');
if (!is_string($path) || $path === '') {
    echo "fail\n";
    exit(1);
}
@unlink($path);
echo "ok\n";
