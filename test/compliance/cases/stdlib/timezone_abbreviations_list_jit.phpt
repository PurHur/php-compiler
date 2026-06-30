--TEST--
stdlib timezone_abbreviations_list() JIT/AOT — timelib map (#11874, ext/date/php_date.c)
--JIT--
--FILE--
<?php
$list = timezone_abbreviations_list();
echo count($list) > 0 ? "non_empty\n" : "empty\n";
echo isset($list['est']) ? "has_est\n" : "no_est\n";
echo $list['est'][0]['timezone_id'] ?? '?', "\n";
--EXPECT--
non_empty
has_est
America/New_York
