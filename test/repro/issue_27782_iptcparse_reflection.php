<?php
/**
 * #27782 — iptcparse Reflection array|false + $iptc_block
 * (ext/standard/basic_functions.stub.php).
 *
 *   ./script/docker-exec.sh -- bash -lc 'php bin/vm.php test/repro/issue_27782_iptcparse_reflection.php'
 */
$r = new ReflectionFunction('iptcparse');
echo 'return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
echo 'param=', $r->getParameters()[0]->getName(), "\n";
var_export(iptcparse(''));
echo "\n";
try {
    var_export(iptcparse(iptc_block: ''));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(iptcparse(iptcdata: ''));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
