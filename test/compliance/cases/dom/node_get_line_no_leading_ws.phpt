--TEST--
dom DOMNode::getLineNo() leading newlines + text/comment lines (#20795, re-#15290)
--FILE--
<?php
$xml = "\n<root>\n  <!--c-->\n  <child/>\n</root>";
$doc = new DOMDocument();
$doc->loadXML($xml);
$root = $doc->documentElement;
$comment = null;
$child = null;
foreach ($root->childNodes as $node) {
    if (null === $comment && XML_COMMENT_NODE === $node->nodeType) {
        $comment = $node;
    }
    if (null === $child && $node instanceof DOMElement) {
        $child = $node;
    }
}
echo $root->getLineNo(), "\n";
echo $root->firstChild->getLineNo(), "\n";
echo $comment->getLineNo(), "\n";
echo $child->getLineNo(), "\n";
--EXPECT--
2
3
3
4
