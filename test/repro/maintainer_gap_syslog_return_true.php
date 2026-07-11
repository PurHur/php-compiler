<?php
declare(strict_types=1);

$result = syslog(LOG_INFO, 'test');
if (true !== $result) {
    echo 'fail: syslog returned ', var_export($result, true), "\n";
    exit(1);
}
echo "ok\n";
