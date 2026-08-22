--TEST--
AOT: DOMElement::getAttributeNode miss/null is bool(false) (#33773)
--FILE--
<?php
require dirname(__DIR__, 3).'/repro/issue_33773_dom_getattrnode_false_aot.php';
--EXPECT--
bool(false)
bool(false)
bool(false)
present=DOMAttr value=v
