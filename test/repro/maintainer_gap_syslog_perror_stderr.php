<?php

declare(strict_types=1);

// Repro #13521 — LOG_PERROR stderr is human ident[pid]: message, not RFC3164 <pri> prefix.
$emitOnly = \in_array('--emit', $argv ?? [], true);

if (!$emitOnly) {
    \openlog('myident', LOG_PID | LOG_PERROR, LOG_USER);
    \syslog(LOG_INFO, 'test message');
    \closelog();
    exit(0);
}

\openlog('myident', LOG_PID | LOG_PERROR, LOG_USER);
\syslog(LOG_INFO, 'test message');
\closelog();
