<?php
// Repro #27512 — AOT pos(null)/pos($a=null) must match Zend TypeError (php-src-strict).
try {
    var_export(pos(null));
    echo " NO_THROW\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
$a = null;
try {
    var_export(pos($a));
    echo " NO_THROW_VAR\n";
} catch (Throwable $e) {
    echo 'var:', get_class($e), ':', $e->getMessage(), "\n";
}
$b = ['x' => 1, 'y' => 2];
echo 'pos=', var_export(pos($b), true), ' cur=', var_export(current($b), true), "\n";
