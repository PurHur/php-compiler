--TEST--
stdlib fscanf() non-seekable pipe input (#15992, ext/standard/fscanf.c)
--FILE--
<?php
$h = popen('printf 99', 'r');
$r = fscanf($h, '%d');
var_export($r);
echo "\n";
pclose($h);
--EXPECT--
array (
  0 => 99,
)
