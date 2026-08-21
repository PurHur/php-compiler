<?php
// AOT: ArrayObject::exchangeArray must replace backing storage (#33083).
$ao = new ArrayObject(['k' => 1]);
$ao->exchangeArray(['z' => 9]);
echo $ao['z'], '|', implode(',', array_keys($ao->getArrayCopy())), "\n";
