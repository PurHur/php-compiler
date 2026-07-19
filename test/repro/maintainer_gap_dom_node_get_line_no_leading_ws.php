<?php

declare(strict_types=1);

/**
 * #20795 — loadXML leading newlines + text/comment getLineNo (re-#15290).
 *
 * Zend/libxml count leading newlines in the source; text nodes report the line
 * after consuming their characters; comments/elements use the start line.
 */
$xml = "\n<root>\n  <!--c-->\n  <child/>\n</root>";
$doc = new DOMDocument();
$doc->loadXML($xml);

$root = $doc->documentElement;
$rootLine = $root->getLineNo();
if (2 !== $rootLine) {
    fwrite(STDERR, "fail: root getLineNo expected 2, got {$rootLine}\n");
    exit(1);
}

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
if (null === $comment) {
    fwrite(STDERR, "fail: comment node not found\n");
    exit(1);
}
if (null === $child) {
    fwrite(STDERR, "fail: child element not found\n");
    exit(1);
}

$commentLine = $comment->getLineNo();
$childLine = $child->getLineNo();
if (3 !== $commentLine) {
    fwrite(STDERR, "fail: comment getLineNo expected 3, got {$commentLine}\n");
    exit(1);
}
if (4 !== $childLine) {
    fwrite(STDERR, "fail: child getLineNo expected 4, got {$childLine}\n");
    exit(1);
}

$firstText = $root->firstChild;
if (null === $firstText || XML_TEXT_NODE !== $firstText->nodeType) {
    fwrite(STDERR, "fail: expected leading text node under root\n");
    exit(1);
}
$textLine = $firstText->getLineNo();
if (3 !== $textLine) {
    fwrite(STDERR, "fail: text getLineNo expected 3, got {$textLine}\n");
    exit(1);
}

echo "ok root=2 text=3 comment=3 child=4\n";
