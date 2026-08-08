--TEST--
goto invalid: jump into switch (Zend parity, #28796)
--FILE--
<?php
goto a;
switch (1) {
  case 1:
    a:
    echo "HIT";
}
--EXPECT_EXIT--
255
