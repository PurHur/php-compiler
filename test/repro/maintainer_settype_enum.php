<?php
enum Es: string { case A = 'a'; }
$v = Es::A;
try {
    settype($v, 'string');
    echo "settype ok: ", var_export($v, true), "\n";
} catch (Throwable $e) {
    echo 'settype ', $e::class, ': ', $e->getMessage(), "\n";
}
