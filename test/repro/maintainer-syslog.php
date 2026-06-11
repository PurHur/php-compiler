<?php

declare(strict_types=1);

var_export(function_exists('openlog'));
var_export(function_exists('syslog'));
var_export(function_exists('closelog'));
var_export(defined('LOG_INFO'));
var_export(LOG_INFO);
echo "\n";

openlog('phpc-test', LOG_PID | LOG_CONS, LOG_USER);
syslog(LOG_INFO, 'parity ok');
closelog();
echo "called\n";
