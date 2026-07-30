<?php
/**
 * Issue #24562 — getprotobyname/getprotobynumber Reflection protocol name.
 */
foreach (['getprotobyname', 'getprotobynumber'] as $fn) {
    $n = [];
    foreach ((new ReflectionFunction($fn))->getParameters() as $p) {
        $n[] = $p->getName();
    }
    echo $fn, '=', implode(',', $n), "\n";
}
try {
    echo 'byname=', var_export(getprotobyname(protocol: 'tcp'), true), "\n";
} catch (Throwable $e) {
    echo 'byname: ', $e->getMessage(), "\n";
}
try {
    echo 'bynum=', var_export(getprotobynumber(protocol: 6), true), "\n";
} catch (Throwable $e) {
    echo 'bynum: ', $e->getMessage(), "\n";
}
