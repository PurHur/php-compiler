<?php

/**
 * AOT: foreach $obj->$p must not abort compile (#35005).
 *
 * Boxed property names (foreach CV) used to structGep __value__ as __object__
 * inside JitStringArg::compileTimeObjectGivenLabel.
 *
 * Run:
 *   PHP_COMPILER_LLVM_ASSERT=1 ./script/docker-exec.sh -- bash -lc \
 *     'source script/php-env.sh; php bin/compile.php -o /tmp/zd.bin test/repro/aot_dynamic_prop_foreach_35005.php && /tmp/zd.bin'
 */
class U
{
    public $status = 0;
    public $filename = '';
}

$u = new U();
$parts = [];
foreach (['status', 'filename'] as $p) {
    $parts[] = $p.'='.var_export($u->$p, true);
}
echo implode(' ', $parts), "\n";
