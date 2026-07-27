--TEST--
convert_uuencode/convert_uudecode named string: arguments (JIT, issue #23784)
--FILE--
<?php
$enc = convert_uuencode(string: 'hi');
echo convert_uudecode(string: $enc), PHP_EOL;
--EXPECT--
hi
