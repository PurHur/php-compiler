--TEST--
stdlib lchown()/lchgrp() null path — warning names callee (#18766, ext/standard/filestat.c)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
@lchown(null, 0);
$err = error_get_last();
echo ($err !== null ? $err['message'] : 'no_error')."\n";

@lchgrp(null, 0);
$err = error_get_last();
echo ($err !== null ? $err['message'] : 'no_error')."\n";
?>
--EXPECT--
lchown(): No such file or directory
lchgrp(): No such file or directory
