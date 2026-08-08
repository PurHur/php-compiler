--TEST--
goto invalid: jump into switch via eval (Zend parity, #28796)
--FILE--
<?php
eval('goto a; switch (1) { case 1: a: echo "HIT"; }');
echo "AFTER";
--EXPECT_EXIT--
255
