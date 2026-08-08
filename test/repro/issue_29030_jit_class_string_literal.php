<?php

// #29030 — string literal "class …" must not skip MCJIT embed bootstrap.
// PHP_COMPILER_PROFILE=8.4 optional; failure mode is JIT module verify without Dom.
$x = 'class after ';
if ($x !== 'class after ') {
    echo var_export($x, true), "\n";
    exit(1);
}
echo "ok\n";
