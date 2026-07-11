--TEST--
stdlib DOMElement::getAttributeNames() — registered on 8.4 development line (#16823, #16975)
--FILE--
<?php
echo method_exists('DOMElement', 'getAttributeNames') ? "exists\n" : "missing\n";
?>
--EXPECT--
exists
