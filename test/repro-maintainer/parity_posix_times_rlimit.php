<?php

declare(strict_types=1);

$t = posix_times();
echo isset($t['ticks']) ? 'times_ok' : 'times_fail', "\n";
echo count(posix_getrlimit()), "\n";
echo function_exists('posix_setsid') ? 'setsid_yes' : 'setsid_no', "\n";
