<?php
// #24379 AOT: named prefix:/flags:/facility: must bind (ext/standard/syslog.c)
openlog(prefix: 'phpc24379', flags: LOG_PID, facility: LOG_USER);
echo "called\n";
closelog();
