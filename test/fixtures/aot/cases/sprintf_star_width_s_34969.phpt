--TEST--
AOT printf/sprintf %*s / %.*s star width+precision — issue #34969
--FILE--
<?php
echo json_encode(sprintf('%*s', 5, 'x')), "\n";
printf("%*s\n", 5, 'x');
echo json_encode(sprintf('%.*s', 2, 'hello')), "\n";
echo json_encode(sprintf('%*.*s', 6, 3, 'abcdef')), "\n";
printf("%*d\n", 5, 42);
echo json_encode(sprintf('%0*d', 5, 1)), "\n";
--EXPECT--
"    x"
    x
"he"
"   abc"
   42
"00001"
