--TEST--
stdlib pcntl_waitpid Reflection names/types match Zend stubs (#27849, ext/pcntl/pcntl.stub.php)
--FILE--
<?php
declare(strict_types=1);

if (!function_exists('pcntl_waitpid')) {
    echo "skip\n";
    exit(0);
}

$r = new ReflectionFunction('pcntl_waitpid');
$parts = [];
foreach ($r->getParameters() as $p) {
    $t = $p->hasType() ? (string) $p->getType() : 'none';
    $parts[] = $p->getName()
        .':'.$t
        .($p->isPassedByReference() ? '&' : '')
        .($p->isOptional() ? '?' : '!');
}
echo implode(',', $parts), "\n";

$st = 0;
$rc = pcntl_waitpid(process_id: -1, status: $st, flags: defined('WNOHANG') ? WNOHANG : 1);
echo 'named=', $rc, "\n";

try {
    pcntl_waitpid(pid: -1, status: $st);
    echo "pid_ok\n";
} catch (Throwable $e) {
    echo "pid_reject\n";
}
--EXPECT--
process_id:int!,status:none&!,flags:int?,resource_usage:none&?
named=-1
pid_reject
