<?php
/**
 * #34814 — AOT ternary with string-returning builtin + string-literal else must not SIGSEGV.
 */
$k = 'abc';
echo is_string($k) ? bin2hex($k) : 'bad', "\n";
echo true ? bin2hex($k) : 'bad', "\n";
echo true ? md5($k) : 'bad', "\n";
$t = true ? bin2hex($k) : 'bad';
echo $t, "\n";
