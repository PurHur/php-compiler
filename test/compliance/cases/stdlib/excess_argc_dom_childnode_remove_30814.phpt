--TEST--
ChildNode::remove() excess argc → ArgumentCountError (#30814)
--RUNFILE--
../../../repro/maintainer_gap_dom_childnode_remove_excess_argc_30814.php
--EXPECT--
DOMElement::remove() expects exactly 0 arguments, 1 given
DOMCharacterData::remove() expects exactly 0 arguments, 1 given
DOMCharacterData::remove() expects exactly 0 arguments, 1 given
DOMCharacterData::remove() expects exactly 0 arguments, 1 given
<r><b/></r>
