--TEST--
goto invalid: jump into finally (Zend parity)
--FILE--
<?php
goto L;
try {
} finally {
    L:
    echo "fin\n";
}
--EXPECT_EXIT--
255

