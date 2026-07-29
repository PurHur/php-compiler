<?php
/** Repro for #24551 — pcntl_signal Reflection + Zend named args (signal/handler). */
$r = new ReflectionFunction('pcntl_signal');
$n = [];
foreach ($r->getParameters() as $p) {
    $n[] = $p->getName();
}
echo 'names=', implode(',', $n), "\n";
try {
    pcntl_signal(signal: SIGTERM, handler: SIG_IGN);
    echo "signal=ok\n";
} catch (Throwable $e) {
    echo 'signal=', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    pcntl_signal(signo: SIGTERM, handle: SIG_IGN);
    echo "signo=ok\n";
} catch (Throwable $e) {
    echo 'signo=', get_class($e), ':', $e->getMessage(), "\n";
}
