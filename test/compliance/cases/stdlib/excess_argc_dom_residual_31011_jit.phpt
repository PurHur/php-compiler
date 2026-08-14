--TEST--
DOM residual methods excess argc → ArgumentCountError JIT (#31011)
--RUNFILE--
../../../repro/maintainer_gap_dom_residual_argc_31011.php
--EXPECT--
DOMDocument::normalizeDocument() expects exactly 0 arguments, 1 given
DOMElement::getElementsByTagName() expects exactly 1 argument, 2 given
DOMElement::getAttributeNS() expects exactly 2 arguments, 3 given
DOMNode::hasAttributes() expects exactly 0 arguments, 1 given
DOMNode::getNodePath() expects exactly 0 arguments, 1 given
DOMNode::C14N() expects at most 4 arguments, 5 given
DOMNodeList::count() expects exactly 0 arguments, 1 given
tagOK
attrOK
hasAttrOK
pathOK
c14nOK
countOK
