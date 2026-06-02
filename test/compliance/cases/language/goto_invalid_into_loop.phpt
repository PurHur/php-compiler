--TEST--
goto invalid: jump into loop (Zend parity)
--FILE--
<?php
goto L;
while (true) {
    L:
    echo "in\n";
    break;
}
--EXPECT_EXIT--
255

