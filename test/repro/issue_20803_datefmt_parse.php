<?php
// #20803 — datefmt_parse / datefmt_localtime / datefmt_get_error_* procedural aliases
$fmt = IntlDateFormatter::create('en_US', IntlDateFormatter::SHORT, IntlDateFormatter::NONE, 'UTC', null, 'yyyy-MM-dd');
echo 'oop_parse=', var_export($fmt->parse('2024-06-15'), true), "\n";
echo 'parse_fn=', function_exists('datefmt_parse') ? 'yes' : 'no', "\n";
echo 'localtime_fn=', function_exists('datefmt_localtime') ? 'yes' : 'no', "\n";
echo 'err_fn=', function_exists('datefmt_get_error_code') ? 'yes' : 'no', "\n";
echo 'errmsg_fn=', function_exists('datefmt_get_error_message') ? 'yes' : 'no', "\n";
echo 'proc_parse=', var_export(datefmt_parse($fmt, '2024-06-15'), true), "\n";
$lt = datefmt_localtime($fmt, '2024-06-15');
echo 'proc_local_y=', $lt['tm_year'] + 1900, ' m=', $lt['tm_mon'] + 1, ' d=', $lt['tm_mday'], "\n";
datefmt_parse($fmt, 'nope');
echo 'err=', datefmt_get_error_code($fmt), ' ', datefmt_get_error_message($fmt), "\n";
