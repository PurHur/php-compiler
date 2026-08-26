--TEST--
stdlib sprintf()/printf() %*s sequential star width (#34969, ext/standard/formatted_print.c)
--FILE--
<?php
declare(strict_types=1);

echo json_encode(sprintf('%*s', 5, 'x')), "\n";
echo json_encode(sprintf('%.*s', 2, 'hello')), "\n";
echo json_encode(sprintf('%*.*s', 6, 3, 'abcdef')), "\n";
echo json_encode(sprintf('%*d', 5, 42)), "\n";
--EXPECT--
"    x"
"he"
"   abc"
"   42"
