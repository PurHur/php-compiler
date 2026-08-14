--TEST--
DOM NS methods excess argc → ArgumentCountError (#31032)
--RUNFILE--
../../../repro/maintainer_gap_dom_ns_argc_31032.php
--EXPECT--
DOMDocument::createElementNS() expects at most 3 arguments, 4 given
DOMDocument::createAttributeNS() expects exactly 2 arguments, 3 given
DOMDocument::getElementsByTagNameNS() expects exactly 2 arguments, 3 given
DOMElement::setAttributeNS() expects exactly 3 arguments, 4 given
DOMElement::removeAttributeNS() expects exactly 2 arguments, 3 given
DOMElement::hasAttributeNS() expects exactly 2 arguments, 3 given
DOMElement::getAttributeNodeNS() expects exactly 2 arguments, 3 given
DOMElement::setAttributeNodeNS() expects exactly 1 argument, 2 given
DOMElement::setIdAttributeNS() expects exactly 3 arguments, 4 given
DOMElement::setIdAttributeNode() expects exactly 2 arguments, 3 given
createNSOK
createAttrNSOK
tagNSOK
setNSOK
hasNSOK
