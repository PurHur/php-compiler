<?php
ini_set('display_errors', '0');
ini_set('error_reporting', '32767');

#[\Deprecated(since: '8.4')]
const FOO = 42;

echo FOO, "\n";
$last = error_get_last();
echo ($last['message'] ?? ''), "\n";
