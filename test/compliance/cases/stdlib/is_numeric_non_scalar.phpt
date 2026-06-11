--TEST--
stdlib is_numeric() on array/object/resource returns false (#5244)
--FILE--
<?php
echo is_numeric([1]) ? 'true' : 'false', "\n";
echo is_numeric(new stdClass()) ? 'true' : 'false', "\n";
$f = fopen('php://memory', 'r+');
echo is_numeric($f) ? 'true' : 'false', "\n";
fclose($f);
--EXPECT--
false
false
false
