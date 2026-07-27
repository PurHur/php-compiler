--TEST--
JIT: checkdate/getdate/gmdate/substr_count Zend stub named params (#23462)
--FILE--
<?php
echo var_export(checkdate(month: 2, day: 29, year: 2020), true), "\n";
echo getdate(timestamp: 0)['year'], "\n";
echo gmdate(format: 'Y', timestamp: 0), "\n";
echo substr_count(haystack: 'abab', needle: 'ab'), "\n";
?>
--EXPECT--
true
1970
1970
2
