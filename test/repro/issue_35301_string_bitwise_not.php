<?php
// AOT: unary ~ on string must link and match Zend (#35301).
// php-src: Zend/zend_operators.c bitwise_not_function string path
var_dump(~'a');
echo bin2hex(~'a'), "\n";
echo bin2hex(~'5'), "\n";
