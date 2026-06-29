--TEST--
stdlib preg_split() splits on embedded NUL via \0 pattern (#13552)
--FILE--
<?php
var_export(preg_split('/\0/', "a\0b"));
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
)
