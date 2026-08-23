<?php
declare(strict_types=1);

foreach (['posix_getcwd', 'posix_uname', 'posix_strerror'] as $f) {
    $r = new ReflectionFunction($f);
    $parts = [];
    foreach ($r->getParameters() as $p) {
        $parts[] = ($p->getType() ? (string) $p->getType() : '?').' $'.$p->getName();
    }
    echo $f, '(', implode(', ', $parts), '):', $r->getReturnType() ? (string) $r->getReturnType() : '?', "\n";
}

echo 'strerror_named ', posix_strerror(error_code: 0), "\n";
try {
    posix_strerror(errno: 0);
    echo "errno_named unexpected_ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
