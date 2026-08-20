--TEST--
stdlib openlog named prefix/flags/facility (#24379, ext/standard/syslog.c)
--FILE--
<?php
$rf = new ReflectionFunction('openlog');
echo implode(',', array_map(fn ($p) => $p->getName(), $rf->getParameters())), "\n";
echo 'positional=', openlog('t', LOG_PID, LOG_USER) ? 'true' : 'false', "\n";
closelog();
try {
    $ok = openlog(prefix: 't', flags: LOG_PID, facility: LOG_USER);
    echo 'named=', $ok ? 'true' : 'false', "\n";
    closelog();
} catch (Error $e) {
    echo 'named:', $e->getMessage(), "\n";
}
try {
    openlog(ident: 't', option: LOG_PID, facility: LOG_USER);
    echo "legacy accepted\n";
    closelog();
} catch (Error $e) {
    echo str_contains($e->getMessage(), 'ident') || str_contains($e->getMessage(), 'option')
        ? "legacy rejected\n"
        : $e->getMessage()."\n";
}
--EXPECT--
prefix,flags,facility
positional=true
named=true
legacy rejected
