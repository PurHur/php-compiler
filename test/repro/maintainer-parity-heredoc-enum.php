<?php

/** Maintainer repro for #5843 — heredoc interpolation on enum case must throw Error. */

enum E: string
{
    case X = 'x';
}

$e = E::X;

try {
    echo <<<HD
e=$e
HD;
    fwrite(STDERR, "FAIL: heredoc did not throw\n");
    exit(1);
} catch (Error $ex) {
    echo $ex->getMessage(), "\n";
    exit(0);
}
