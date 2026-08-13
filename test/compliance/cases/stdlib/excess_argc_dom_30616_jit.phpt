--TEST--
DOM instance methods excess argc → ArgumentCountError JIT (#30616)
--RUNFILE--
../../../repro/maintainer_gap_dom_excess_argc_30616.php
--EXPECT--
DOMNode::appendChild() expects exactly 1 argument, 2 given
DOMNode::removeChild() expects exactly 1 argument, 2 given
DOMNode::cloneNode() expects at most 1 argument, 2 given
DOMNode::hasChildNodes() expects exactly 0 arguments, 1 given
DOMNode::normalize() expects exactly 0 arguments, 1 given
DOMNode::isSameNode() expects exactly 1 argument, 2 given
DOMDocument::getElementById() expects exactly 1 argument, 2 given
DOMDocument::createElement() expects at most 2 arguments, 3 given
DOMDocument::createTextNode() expects exactly 1 argument, 2 given
DOMDocument::createAttribute() expects exactly 1 argument, 2 given
DOMDocument::createComment() expects exactly 1 argument, 2 given
DOMDocument::getElementsByTagName() expects exactly 1 argument, 2 given
DOMDocument::loadXML() expects at most 2 arguments, 3 given
DOMDocument::saveXML() expects at most 2 arguments, 3 given
DOMDocument::saveHTML() expects at most 1 argument, 2 given
DOMDocument::xinclude() expects at most 1 argument, 2 given
DOMDocument::validate() expects exactly 0 arguments, 1 given
DOMElement::setAttribute() expects exactly 2 arguments, 3 given
DOMElement::getAttribute() expects exactly 1 argument, 2 given
DOMElement::hasAttribute() expects exactly 1 argument, 2 given
DOMElement::removeAttribute() expects exactly 1 argument, 2 given
DOMDocument::importNode() expects at most 2 arguments, 3 given
DOMNode::insertBefore() expects at most 2 arguments, 3 given
DOMNode::replaceChild() expects exactly 2 arguments, 3 given
DOMXPath::query() expects at most 3 arguments, 4 given
DOMXPath::evaluate() expects at most 3 arguments, 4 given
DOMXPath::registerNamespace() expects exactly 2 arguments, 3 given
