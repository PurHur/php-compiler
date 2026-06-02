--TEST--
goto invalid: jump out of finally (Zend parity)
--FILE--
<?php
try {
} finally {
    goto out;
}
out:
echo "x\n";
--EXPECT_EXIT--
255

