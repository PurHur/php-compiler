--TEST--
DOM residual methods excess argc → ArgumentCountError JIT (#31251)
--RUNFILE--
../../../repro/maintainer_gap_dom_residual_argc_31251.php
--EXPECT--
DOMNode::lookupPrefix() expects exactly 1 argument, 2 given
DOMNode::lookupNamespaceURI() expects exactly 1 argument, 2 given
DOMNode::isDefaultNamespace() expects exactly 1 argument, 2 given
DOMNode::isSupported() expects exactly 2 arguments, 3 given
DOMNode::C14NFile() expects at most 5 arguments, 6 given
DOMDocument::schemaValidate() expects at most 2 arguments, 3 given
DOMDocument::schemaValidateSource() expects at most 2 arguments, 3 given
DOMDocument::relaxNGValidate() expects exactly 1 argument, 2 given
DOMDocument::relaxNGValidateSource() expects exactly 1 argument, 2 given
DOMDocument::load() expects at most 2 arguments, 3 given
DOMDocument::save() expects at most 2 arguments, 3 given
DOMDocument::saveHTMLFile() expects exactly 1 argument, 2 given
DOMDocument::createCDATASection() expects exactly 1 argument, 2 given
DOMDocument::createDocumentFragment() expects exactly 0 arguments, 1 given
DOMDocument::createEntityReference() expects exactly 1 argument, 2 given
DOMDocument::createProcessingInstruction() expects at most 2 arguments, 3 given
DOMDocument::registerNodeClass() expects exactly 2 arguments, 3 given
DOMElement::setAttributeNode() expects exactly 1 argument, 2 given
DOMElement::removeAttributeNode() expects exactly 1 argument, 2 given
DOMXPath::registerPhpFunctions() expects at most 1 argument, 2 given
p
featOK
fragOK
phpFnOK
