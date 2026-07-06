--TEST--
stdlib DOMElement::getAttributeNames() phantom — withheld on 8.4.0-dev reference profile (#16823)
--FILE--
<?php
echo method_exists('DOMElement', 'getAttributeNames') ? "exists\n" : "missing\n";
?>
--EXPECT--
missing
