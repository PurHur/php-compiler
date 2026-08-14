--TEST--
SimpleXML methods/import excess argc → ArgumentCountError JIT (#30828)
--RUNFILE--
../../../repro/maintainer_gap_simplexml_excess_argc_30828.php
--EXPECT--
SimpleXMLElement::children() expects at most 2 arguments, 3 given
SimpleXMLElement::attributes() expects at most 2 arguments, 3 given
SimpleXMLElement::xpath() expects exactly 1 argument, 2 given
SimpleXMLElement::registerXPathNamespace() expects exactly 2 arguments, 3 given
SimpleXMLElement::addChild() expects at most 3 arguments, 4 given
SimpleXMLElement::addAttribute() expects at most 3 arguments, 4 given
dom_import_simplexml() expects exactly 1 argument, 2 given
simplexml_import_dom() expects at most 2 arguments, 3 given
simplexml_load_string() expects at most 5 arguments, 6 given
SimpleXMLElement::getName() expects exactly 0 arguments, 1 given
SimpleXMLElement::count() expects exactly 0 arguments, 1 given
SimpleXMLElement::getNamespaces() expects at most 1 argument, 2 given
SimpleXMLElement::getDocNamespaces() expects at most 2 arguments, 3 given
SimpleXMLElement::asXML() expects at most 1 argument, 2 given
SimpleXMLElement::saveXML() expects at most 1 argument, 2 given
simplexml_load_file() expects at most 5 arguments, 6 given
SimpleXMLElement::__construct() expects at most 5 arguments, 6 given
SimpleXMLElement::__toString() expects exactly 0 arguments, 1 given
SimpleXMLElement::hasChildren() expects exactly 0 arguments, 1 given
SimpleXMLElement::getChildren() expects exactly 0 arguments, 1 given
ok=r,3,r
