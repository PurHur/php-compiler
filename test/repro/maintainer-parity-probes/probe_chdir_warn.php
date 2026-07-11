<?php

$missing = '/nonexistent/chdir_' . getmypid() . '_' . time();
$cwdBefore = getcwd();
$result = chdir($missing);
echo 'chdir result: ';
var_export($result);
echo "\ncwd after: ";
var_export(getcwd());
echo "\n";
