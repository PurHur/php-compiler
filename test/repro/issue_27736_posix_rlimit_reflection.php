<?php
// Repro #27736 — posix_getrlimit/posix_setrlimit Reflection + named args (posix.stub.php)
foreach (['posix_getrlimit', 'posix_setrlimit'] as $f) {
    $r = new ReflectionFunction($f);
    echo $f, ' arity=', $r->getNumberOfParameters(), ' req=', $r->getNumberOfRequiredParameters();
    echo ' return=', $r->hasReturnType() ? (string) $r->getReturnType() : '(none)', "\n";
    foreach ($r->getParameters() as $p) {
        echo '  ', $p->getName(), ':', $p->hasType() ? (string) $p->getType() : 'none', "\n";
    }
}
$lim = posix_getrlimit();
$soft = $lim['soft openfiles'];
$hard = $lim['hard openfiles'];
if ('unlimited' === $soft) {
    $soft = POSIX_RLIMIT_INFINITY;
}
if ('unlimited' === $hard) {
    $hard = POSIX_RLIMIT_INFINITY;
}
try {
    $ok = posix_setrlimit(resource: POSIX_RLIMIT_NOFILE, soft_limit: (int) $soft, hard_limit: (int) $hard);
    echo 'named=', is_bool($ok) ? 'ok' : gettype($ok), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
