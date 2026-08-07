<?php
/** Repro #28349 — zlib_encode/zlib_decode Reflection returns string|false. */
foreach (['zlib_encode', 'zlib_decode', 'gzencode'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, '=', $r->hasReturnType() ? (string) $r->getReturnType() : '-', PHP_EOL;
}
