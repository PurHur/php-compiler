--TEST--
DOMNodeList / DOMNamedNodeMap IteratorAggregate + InternalIterator (#21298, #21466, ext/dom/php_dom.stub.php)
--FILE--
<?php
$doc = new DOMDocument();
$doc->loadXML('<r a="1" b="2"><c/><d/></r>');
$nl = $doc->getElementsByTagName('*');
$map = $doc->documentElement->attributes;

echo 'NL_IA=', $nl instanceof IteratorAggregate ? 'yes' : 'no', "\n";
echo 'NL_I=', $nl instanceof Iterator ? 'yes' : 'no', "\n";
echo 'NL_getIterator=', method_exists($nl, 'getIterator') ? 'yes' : 'no', "\n";
echo 'Map_IA=', $map instanceof IteratorAggregate ? 'yes' : 'no', "\n";
echo 'Map_I=', $map instanceof Iterator ? 'yes' : 'no', "\n";
echo 'Map_getIterator=', method_exists($map, 'getIterator') ? 'yes' : 'no', "\n";

$it = $nl->getIterator();
echo 'NL_it_class=', get_class($it), "\n";
echo 'NL_it=', $it instanceof Iterator ? 'yes' : 'no', "\n";
foreach ($nl as $k => $node) {
    echo "nl[$k]=", $node->nodeName, "\n";
}

$mit = $map->getIterator();
echo 'Map_it_class=', get_class($mit), "\n";
echo 'Map_it=', $mit instanceof Iterator ? 'yes' : 'no', "\n";
foreach ($map as $k => $attr) {
    echo 'map_key_type=', gettype($k), ' map[', $k, ']=', $attr->name, "\n";
}
?>
--EXPECT--
NL_IA=yes
NL_I=no
NL_getIterator=yes
Map_IA=yes
Map_I=no
Map_getIterator=yes
NL_it_class=InternalIterator
NL_it=yes
nl[0]=r
nl[1]=c
nl[2]=d
Map_it_class=InternalIterator
Map_it=yes
map_key_type=string map[a]=a
map_key_type=string map[b]=b
