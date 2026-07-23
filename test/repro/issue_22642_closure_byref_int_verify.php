<?php

declare(strict_types=1);

/**
 * Repro for #22642: by-ref int closure capture must module-verify (writeLong/valueDelref types).
 * Compile: PHP_COMPILER_LLVM_ASSERT=1 php bin/compile.php -o /tmp/i22642 test/repro/issue_22642_closure_byref_int_verify.php
 */
$n = 0;
$inc = function () use (&$n): void {
    $n++;
};
$inc();
$inc();
echo $n, "\n";
