--TEST--
Language: named builtin calls with variable operands (#12123, Zend/zend_compile.c)
--FILE--
<?php
$x = 'aca';
echo str_replace(search: 'a', replace: 'b', subject: $x), "\n";
$utc = new DateTimeZone('UTC');
$immutable = new DateTimeImmutable(datetime: '2020-03-04', timezone: $utc);
echo $immutable->format('Y-m-d'), "\n";
?>
--EXPECT--
bcb
2020-03-04
