--TEST--
DOM loadHTML/NodeList/NamedNodeMap excess argc → ArgumentCountError JIT (#30835)
--RUNFILE--
../../../repro/maintainer_gap_dom_loadhtml_nodelist_excess_argc_30835.php
--EXPECT--
DOMDocument::loadHTML() expects at most 2 arguments, 3 given
DOMDocument::loadHTMLFile() expects at most 2 arguments, 3 given
DOMNodeList::item() expects exactly 1 argument, 2 given
DOMNamedNodeMap::item() expects exactly 1 argument, 2 given
DOMNamedNodeMap::getNamedItem() expects exactly 1 argument, 2 given
loadOK
ok
ok
