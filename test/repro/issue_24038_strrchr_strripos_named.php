<?php
// Repro #24038 — strrchr/strripos Reflection OK but named args rejected (call-site binder gap)
foreach (['strrchr', 'strripos', 'stristr'] as $fn) {
    $r = new ReflectionFunction($fn);
    echo $fn, ' ref=';
    foreach ($r->getParameters() as $p) {
        echo $p->getName(), ';';
    }
    echo "\n";
}
try {
    echo 'strrchr=', strrchr(haystack: 'abcbd', needle: 'b'), "\n";
} catch (Throwable $e) {
    echo 'strrchr_err=', $e->getMessage(), "\n";
}
try {
    echo 'strripos=', strripos(haystack: 'ababd', needle: 'AB'), "\n";
} catch (Throwable $e) {
    echo 'strripos_err=', $e->getMessage(), "\n";
}
try {
    echo 'stristr=', stristr(haystack: 'abCde', needle: 'c'), "\n";
} catch (Throwable $e) {
    echo 'stristr_err=', $e->getMessage(), "\n";
}
