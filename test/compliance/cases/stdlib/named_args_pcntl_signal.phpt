--TEST--
pcntl_signal Reflection + Zend named args (VM, issue #24551)
--FILE--
<?php
if (!function_exists('pcntl_signal') || !defined('SIGTERM') || !defined('SIG_IGN')) {
    echo "skip\n";
    exit(0);
}
$r = new ReflectionFunction('pcntl_signal');
$n = [];
foreach ($r->getParameters() as $p) {
    $n[] = $p->getName();
}
echo 'names=', implode(',', $n), PHP_EOL;
try {
    pcntl_signal(signal: SIGTERM, handler: SIG_IGN);
    echo "signal=ok\n";
} catch (Throwable $e) {
    echo 'signal=', get_class($e), ':', $e->getMessage(), PHP_EOL;
}
try {
    pcntl_signal(signo: SIGTERM, handle: SIG_IGN);
    echo "signo=ok\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
--EXPECT--
names=signal,handler,restart_syscalls
signal=ok
Unknown named parameter $signo
