<?php
// AOT: unary ~ on string *variable* must be byte-wise, not int coerce (#35305).
// php-src: Zend/zend_operators.c bitwise_not_function string path
$s = 'a';
var_dump(~$s);
echo bin2hex(~$s), "\n";
$t = 'ab';
echo bin2hex(~$t), "\n";
var_dump(~'a');
