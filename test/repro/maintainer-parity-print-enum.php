<?php

/** Maintainer repro for #5843 — print backed enum case must throw Error (zend_operators.c). */

enum I: int
{
    case A = 1;
}

try {
    print I::A;
    fwrite(STDERR, "FAIL: print did not throw\n");
    exit(1);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
    exit(0);
}
