--TEST--
posix_getrlimit/posix_setrlimit Reflection + named args (VM, issue #27736, posix.stub.php)
--FILE--
<?php
foreach (['posix_getrlimit', 'posix_setrlimit'] as $f) {
    $r = new ReflectionFunction($f);
    $parts = [];
    foreach ($r->getParameters() as $p) {
        $parts[] = ($p->getType() ? (string) $p->getType() : '?').' $'.$p->getName();
    }
    echo $f, '(', implode(', ', $parts), '):', $r->hasReturnType() ? (string) $r->getReturnType() : '?', "\n";
    echo $f, '_argc=', $r->getNumberOfParameters(), ' req=', $r->getNumberOfRequiredParameters(), "\n";
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
$ok = posix_setrlimit(resource: POSIX_RLIMIT_NOFILE, soft_limit: (int) $soft, hard_limit: (int) $hard);
echo 'named=', is_bool($ok) ? 'ok' : gettype($ok), "\n";
?>
--EXPECT--
posix_getrlimit():array|false
posix_getrlimit_argc=0 req=0
posix_setrlimit(int $resource, int $soft_limit, int $hard_limit):bool
posix_setrlimit_argc=3 req=3
named=ok
