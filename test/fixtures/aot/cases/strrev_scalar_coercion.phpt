--TEST--
AOT strrev() scalar coercion (#4552)
--FILE--
<?php
echo strrev(123), "\n";
echo strrev(456), "\n";
--EXPECT--
321
654
