<?php

/** Maintainer repro for #4891 — echo backed enum case must throw Error (Zend zend_enum.c). */

enum E: string
{
    case A = 'a';
}

try {
    echo E::A;
    fwrite(STDERR, "FAIL: echo did not throw\n");
    exit(1);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
    exit(0);
}
