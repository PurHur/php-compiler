--TEST--
stdlib unpack() fixed-width A strips trailing SPACE pad JIT (issue #29006)
--FILE--
<?php
echo 'A4 => ', var_export(unpack('A4', 'ab  ')[1], true), "\n";
echo 'A3 => ', var_export(unpack('A3', 'ab ')[1], true), "\n";
echo 'a4 => ', var_export(unpack('a4', 'ab  ')[1], true), "\n";
echo 'A* => ', var_export(unpack('A*', 'ab  ')[1], true), "\n";
--EXPECT--
A4 => 'ab'
A3 => 'ab'
a4 => 'ab  '
A* => 'ab'
