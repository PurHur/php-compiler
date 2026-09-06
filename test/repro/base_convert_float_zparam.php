<?php
// Z_PARAM_STR float coerce for base_convert — must match Zend (#36386).
$v = PHP_INT_MAX + 1;
echo base_convert($v, 10, 16), "\n";
echo base_convert((string)$v, 10, 16), "\n";
echo base_convert(255.0, 10, 16), "\n";
$f = 255.0;
echo base_convert($f, 10, 16), "\n";
echo base_convert(1.5e20, 10, 16), "\n";
