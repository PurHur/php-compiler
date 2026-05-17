--TEST--
stdlib exp()
--FILE--
<?php
echo exp(0), "\n";
echo intval(exp(1) * 1000), "\n";
echo intval(exp(2) * 100), "\n";
--EXPECT--
1
2718
738
