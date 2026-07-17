--TEST--
DOMNode::C14N() xpath nodeset + DOMXPath relative `.` / `.//` (#20257, ext/dom/node.c)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<root xmlns:a="urn:a"><a:x id="1">A</a:x><y id="2">B</y><z>C</z></root>');
$el = $doc->documentElement;
$xp = new DOMXPath($doc);
echo (1 === $xp->query('.//y', $el)->length) ? "rel " : "rel-fail ";
echo (1 === $xp->query('.', $el)->length) ? "dot " : "dot-fail ";
$ex = false;
$wc = false;
echo ($el->C14N($ex, $wc, ['query' => './/y']) === '<y></y>') ? "y " : "y-fail ";
echo ($el->C14N($ex, $wc, ['query' => './/*']) === '<a:x></a:x><y></y><z></z>') ? "star " : "star-fail ";
echo ($el->C14N($ex, $wc, ['query' => '.']) === '<root></root>') ? "self " : "self-fail ";
echo ($el->C14N($ex, $wc, ['query' => './/y | .//y/@id']) === '<y id="2"></y>') ? "attr " : "attr-fail ";
echo ($el->C14N($ex, $wc, ['query' => './/missing']) === '') ? "empty " : "empty-fail ";
try {
    $el->C14N($ex, $wc, ['foo' => 'bar']);
    echo "value-fail\n";
} catch (ValueError $e) {
    echo str_contains($e->getMessage(), 'must have a "query" key') ? "value\n" : "value-msg-fail\n";
}
?>
--EXPECT--
rel dot y star self attr empty value
