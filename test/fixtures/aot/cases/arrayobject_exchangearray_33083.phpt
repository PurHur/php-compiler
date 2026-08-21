--TEST--
ArrayObject::exchangeArray replaces __spl_ht (#33083, ext/spl/spl_array.c)
--FILE--
<?php
$ao = new ArrayObject(['k' => 1]);
$old = $ao->exchangeArray(['z' => 9]);
echo $ao['z'], '|', implode(',', array_keys($ao->getArrayCopy())), '|', $old['k'], "\n";
?>
--EXPECT--
9|z|1
