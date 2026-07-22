--TEST--
DOMNodeList live foreach + removeChild skip / stop semantics (#21930, ext/dom/nodelist.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r><a id="1"/><a id="2"/><a id="3"/></r>');
$list = $d->getElementsByTagName('a');
$seen = [];
foreach ($list as $k => $node) {
    $seen[] = $k . ':' . $node->getAttribute('id');
    if ($node->getAttribute('id') === '1') {
        $node->parentNode->removeChild($node);
    }
}
echo 'tag_remove1=', implode(',', $seen), ' len=', $list->length, "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r><a id="1"/><a id="2"/><a id="3"/></r>');
$list2 = $d2->getElementsByTagName('a');
$seen2 = [];
foreach ($list2 as $k => $node) {
    $seen2[] = $k . ':' . $node->getAttribute('id');
    if ($node->getAttribute('id') === '2') {
        $node->parentNode->removeChild($node);
    }
}
echo 'tag_remove2=', implode(',', $seen2), ' len=', $list2->length, "\n";

$d3 = new DOMDocument();
$d3->loadXML('<r><a id="1"/><a id="2"/><a id="3"/></r>');
$r = $d3->documentElement;
$seen3 = [];
foreach ($r->childNodes as $k => $node) {
    if ($node->nodeType !== XML_ELEMENT_NODE) {
        continue;
    }
    $seen3[] = $k . ':' . $node->getAttribute('id');
    if ($node->getAttribute('id') === '1') {
        $r->removeChild($node);
    }
}
echo 'child_remove1=', implode(',', $seen3), ' len=', $r->childNodes->length, "\n";

$d4 = new DOMDocument();
$d4->loadXML('<r><a id="1"/><a id="2"/><a id="3"/></r>');
$it = $d4->getElementsByTagName('a')->getIterator();
echo 'it_class=', get_class($it), "\n";
echo "done\n";
?>
--EXPECT--
tag_remove1=0:1,1:3 len=2
tag_remove2=0:1,1:2 len=2
child_remove1=0:1 len=2
it_class=InternalIterator
done
