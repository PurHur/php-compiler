--TEST--
stdlib DOMNode::isSupported matches Zend dom_has_feature (#32480, ext/dom/php_dom.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root id="x"><child/></root>');
$root = $doc->documentElement;
echo (int) $root->isSupported('Core', '1.0'), '|';
echo (int) $root->isSupported('Core', '2.0'), '|';
echo (int) $root->isSupported('XML', '1.0'), '|';
echo (int) $root->isSupported('XML', '2.0'), '|';
echo (int) $root->isSupported('XML', '3.0'), '|';
echo (int) $root->isSupported('HTML', '1.0'), "\n";
--EXPECT--
1|0|1|1|0|0
