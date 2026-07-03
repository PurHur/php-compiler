--TEST--
DOM DOMDocument::$implementation exposes DOMImplementation (ext/dom/php_dom.c; #15252)
--FILE--
<?php
$doc = new DOMDocument();
$impl = $doc->implementation;
echo null !== $impl ? "impl_ok\n" : "impl_null\n";
echo $impl->hasFeature('XML', '2.0') ? "has_xml\n" : "no_xml\n";
--EXPECT--
impl_ok
has_xml
