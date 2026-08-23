--TEST--
posix_getpwuid/kill Reflection stub names + getpwuid array|false (VM, issue #24374, posix.stub.php)
--FILE--
<?php
declare(strict_types=1);

foreach (['posix_getpwuid', 'posix_kill', 'posix_strerror'] as $f) {
    $r = new ReflectionFunction($f);
    $parts = [];
    foreach ($r->getParameters() as $p) {
        $parts[] = ($p->getType() ? (string) $p->getType() : '?').' $'.$p->getName();
    }
    echo $f, '(', implode(', ', $parts), '):', $r->getReturnType() ? (string) $r->getReturnType() : '?', "\n";
}

$pw = posix_getpwuid(user_id: posix_getuid());
echo 'getpwuid_named ', is_array($pw) ? 'array' : var_export($pw, true), "\n";
try {
    posix_getpwuid(uid: 0);
    echo "uid_named unexpected_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

echo 'kill_named ', var_export(posix_kill(process_id: getmypid(), signal: 0), true), "\n";
try {
    posix_kill(pid: getmypid(), sig: 0);
    echo "pid_sig_named unexpected_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

echo 'strerror_named ', posix_strerror(error_code: 0), "\n";
?>
--EXPECT--
posix_getpwuid(int $user_id):array|false
posix_kill(int $process_id, int $signal):bool
posix_strerror(int $error_code):string
getpwuid_named array
Error:Unknown named parameter $uid
kill_named true
Error:Unknown named parameter $pid
strerror_named Success
