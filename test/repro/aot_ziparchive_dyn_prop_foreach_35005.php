<?php

/**
 * AOT: ZipArchive foreach $z->$p (#35005 leftover of #35002).
 *
 * Run:
 *   PHP_COMPILER_ENABLE_ZIP=1 PHP_COMPILER_LLVM_ASSERT=1 \
 *     ./script/docker-exec.sh -- bash -lc \
 *     'source script/php-env.sh; php bin/compile.php -o /tmp/zz.bin test/repro/aot_ziparchive_dyn_prop_foreach_35005.php && /tmp/zz.bin'
 */
$z = new ZipArchive();
$parts = [];
foreach (['status', 'statusSys', 'lastId', 'filename', 'numFiles', 'comment'] as $p) {
    $parts[] = $p.'='.var_export($z->$p, true);
}
echo implode(' ', $parts), "\n";
