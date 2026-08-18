--TEST--
stdlib var_dump(INF/NAN) — zend_gcvt uppercase tokens (#32321)
--FILE--
<?php
var_dump(INF);
var_dump(NAN);
var_dump(1.5);
--EXPECT--
float(INF)
float(NAN)
float(1.5)
