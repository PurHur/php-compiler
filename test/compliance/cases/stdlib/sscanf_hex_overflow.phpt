--TEST--
stdlib sscanf() %x/%X overflow clamps to PHP_INT_MAX (#15327, ext/standard/formatted_io.c)
--FILE--
<?php
$expected = 9223372036854775807;
$x = sscanf('FFFFFFFFFFFFFFFF', '%x');
echo ($x[0] ?? 'null'), "\n";
$X = sscanf('FFFFFFFFFFFFFFFF', '%X');
echo ($X[0] ?? 'null'), "\n";
--EXPECT--
9223372036854775807
9223372036854775807
