--TEST--
DOMNamedNodeMap live foreach + removeAttribute stop / advance semantics (#21931, ext/dom/nodemap.c)
--FILE--
<?php
$d = new DOMDocument();
$d->loadXML('<r a="1" b="2" c="3"/>');
$m = $d->documentElement->attributes;
$seen = [];
foreach ($m as $attr) {
    $seen[] = $attr->name;
    if ($attr->name === 'a') {
        $d->documentElement->removeAttribute('a');
    }
}
echo 'remove_current=', implode(',', $seen), ' len=', $m->length, "\n";

$d2 = new DOMDocument();
$d2->loadXML('<r a="1" b="2" c="3"/>');
$m2 = $d2->documentElement->attributes;
$seen2 = [];
foreach ($m2 as $attr) {
    $seen2[] = $attr->name;
    if ($attr->name === 'a') {
        $d2->documentElement->removeAttribute('c');
    }
}
echo 'remove_later=', implode(',', $seen2), ' len=', $m2->length, "\n";

$d3 = new DOMDocument();
$d3->loadXML('<r a="1" b="2" c="3"/>');
$m3 = $d3->documentElement->attributes;
$seen3 = [];
foreach ($m3 as $attr) {
    $seen3[] = $attr->name;
    if ($attr->name === 'a') {
        $d3->documentElement->removeAttribute('b');
    }
}
echo 'remove_next=', implode(',', $seen3), ' len=', $m3->length, "\n";

$d4 = new DOMDocument();
$d4->loadXML('<r a="1" b="2" c="3"/>');
$m4 = $d4->documentElement->attributes;
$seen4 = [];
foreach ($m4 as $attr) {
    $seen4[] = $attr->name;
    if ($attr->name === 'b') {
        $d4->documentElement->removeAttribute('a');
    }
}
echo 'remove_prev=', implode(',', $seen4), ' len=', $m4->length, "\n";

$d5 = new DOMDocument();
$d5->loadXML('<r a="1" b="2"/>');
$it = $d5->documentElement->attributes->getIterator();
echo 'it_class=', get_class($it), "\n";
echo "done\n";
?>
--EXPECT--
remove_current=a len=2
remove_later=a,b len=2
remove_next=a,c len=2
remove_prev=a,b,c len=2
it_class=InternalIterator
done
