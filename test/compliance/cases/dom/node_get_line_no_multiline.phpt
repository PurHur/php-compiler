--TEST--
dom DOMNode::getLineNo() multiline XML source line (#15290)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML("<root>\n  <child/>\n</root>");
$child = $doc->documentElement->firstChild;
if (!$child instanceof DOMElement) {
    foreach ($doc->documentElement->childNodes as $node) {
        if ($node instanceof DOMElement) {
            $child = $node;
            break;
        }
    }
}
echo $child->getLineNo(), "\n";
--EXPECT--
2
