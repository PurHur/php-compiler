<?php
// #24379 — Zend openlog(string $prefix, int $flags, int $facility): bool
$rf = new ReflectionFunction('openlog');
echo implode(',', array_map(fn ($p) => $p->getName(), $rf->getParameters())), "\n";
echo 'positional=', openlog('t', LOG_PID, LOG_USER) ? 'true' : 'false', "\n";
closelog();
try {
    $ok = openlog(prefix: 't', flags: LOG_PID, facility: LOG_USER);
    echo 'prefix-ok=', $ok ? 'true' : 'false', "\n";
    closelog();
} catch (Throwable $e) {
    echo 'prefix:', $e->getMessage(), "\n";
}
try {
    openlog(ident: 't', option: LOG_PID, facility: LOG_USER);
    echo "legacy-ok\n";
    closelog();
} catch (Throwable $e) {
    echo 'legacy:', $e->getMessage(), "\n";
}
