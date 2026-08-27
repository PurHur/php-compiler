--TEST--
AOT session_register_shutdown() (#35330 leftover #4873)
--FILE--
<?php
session_id('abcdefghijklmnop');
session_start();
$_SESSION['x'] = 42;
$r = session_register_shutdown();
var_dump($r);
echo 'reg', "\n";
--EXPECT--
NULL
reg
