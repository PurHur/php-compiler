--TEST--
stdlib method_exists() — inherited ext/dom methods on class name strings (#19178, ext/standard/class.c)
--FILE--
<?php
echo method_exists(DOMElement::class, 'after') ? '1' : '0';
echo method_exists(DOMNode::class, 'appendChild') ? '1' : '0';
$el = new DOMElement('div');
echo method_exists($el, 'after') ? '1' : '0';
echo "\n";
?>
--EXPECT--
111
