<?php
// Issue #29006 — fixed-width unpack('A$n') must strip trailing SPACE pad like Zend.
echo 'A4 => ', var_export(unpack('A4', 'ab  ')[1], true), "\n";
echo 'A3 => ', var_export(unpack('A3', 'ab ')[1], true), "\n";
echo 'A5 => ', var_export(unpack('A5', "ab\t\r\n")[1], true), "\n";
echo 'A4nul => ', var_export(unpack('A4', "ab\0\0")[1], true), "\n";
echo 'A5lead => ', var_export(unpack('A5', '  ab ')[1], true), "\n";
echo 'a4 => ', var_export(unpack('a4', 'ab  ')[1], true), "\n";
echo 'A* => ', var_export(unpack('A*', 'ab  ')[1], true), "\n";
