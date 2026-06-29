--TEST--
Stdlib: filter_list() — php-src filter names (#11419, ext/filter/filter.c)
--FILE--
<?php
$list = filter_list();
echo count($list), "\n";
echo in_array('string', $list, true) ? '1' : '0', "\n";
echo in_array('int', $list, true) ? '1' : '0', "\n";
echo in_array('validate_ip', $list, true) ? '1' : '0', "\n";
--EXPECT--
21
1
1
1
