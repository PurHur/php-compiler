<?php

declare(strict_types=1);

if (!\PHPCompiler\CompilerVersion::supportsBz2()) {
    echo "skip: bz2 withheld on reference profile\n";
    exit(0);
}

require __DIR__.'/maintainer_gap_bz2_compress_withheld.php';
