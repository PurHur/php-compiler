<?php
// Repro #30865 — ReflectionEnum::getCases/hasCase/getCase excess/missing argc → ArgumentCountError
enum E { case A; }
$r = new ReflectionEnum('E');
try {
    echo count($r->getCases(1)), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export($r->hasCase('A', 1));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo $r->getCase('A', 1)->getName(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export($r->hasCase());
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    echo $r->getCase()->getName(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'ok=', count($r->getCases()), ',', $r->hasCase('A') ? '1' : '0', ',', $r->getCase('A')->getName(), "\n";
