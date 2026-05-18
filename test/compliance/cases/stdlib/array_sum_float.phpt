--TEST--
stdlib array_sum() promotes to float when needed
--FILE--
<?php
echo array_sum(array(1, 2.5)), "\n";
echo array_sum(array(1.5, 2.5)), "\n";
--EXPECT--
3.5
4
